<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
if (is_file(__DIR__ . '/../../auth/tokenomics.php')) {
    require_once __DIR__ . '/../../auth/tokenomics.php';
}

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
    cue_autoload('paths');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') $redirect = '/hub/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
$isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
if (!$isKripz) {
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($u !== '' && function_exists('mh_auth_load_user_context')) {
        try { mh_auth_load_user_context($u); } catch (Throwable $e) {}
        $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
        $isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
    }
}
if (!$isKripz) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

function mh_custom_notices_paths(): array
{
    $dataBase = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $dataBase = $dataBase !== '' ? rtrim($dataBase, '/') : '/data';
    $file = $dataBase . '/widgets/notices/custom-notices.json';
    try {
        $paths = cue_autoload('paths');
        if ($paths) {
            $dataBase = (string)$paths->getDataPath();
            $dataBase = $dataBase !== '' ? rtrim($dataBase, DIRECTORY_SEPARATOR) : '/data';
            $fileCandidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'custom-notices.json';
            $safe = $paths->validateSecurePath($fileCandidate, $dataBase);
            if (is_string($safe) && $safe !== '') $file = $safe;
        }
    } catch (Throwable) {}
    return [$dataBase, $file];
}

function mh_custom_notices_read_legacy(string $dataBase): array
{
    $file = rtrim($dataBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'widgets-config.json';
    try {
        $paths = cue_autoload('paths');
        if ($paths) {
            $candidate = rtrim((string)$paths->getDataPath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'widgets-config.json';
            $safe = $paths->validateSecurePath($candidate, (string)$paths->getDataPath());
            if (is_string($safe) && $safe !== '') $file = $safe;
        }
    } catch (Throwable) {}
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $list = isset($decoded['custom_messages']) && is_array($decoded['custom_messages']) ? $decoded['custom_messages'] : [];
    return is_array($list) ? $list : [];
}

function mh_custom_notices_read(string $filePath): array
{
    if (!is_file($filePath)) return [];
    $raw = @file_get_contents($filePath);
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function mh_custom_notices_write(string $dataBase, string $filePath, array $payload): bool
{
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) return false;
    $enc = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($enc)) return false;
    return @file_put_contents($filePath, $enc . "\n") !== false;
}

function mh_custom_notices_now(): string
{
    return date('Y-m-d H:i:s');
}

function mh_custom_notices_norm_dt(string $v): string
{
    $v = trim($v);
    if ($v === '') return '';
    $t = strtotime($v);
    if (!$t) return '';
    return date('Y-m-d H:i:s', $t);
}

[$dataBase, $filePath] = mh_custom_notices_paths();
$message = '';
$error = '';

$prefill = isset($_GET['prefill']) ? trim((string)$_GET['prefill']) : '';
if ($prefill === 'culture_reservation') {
    try {
        $cfg = mh_custom_notices_read($filePath);
        $list = isset($cfg['custom_messages']) && is_array($cfg['custom_messages']) ? $cfg['custom_messages'] : [];
        if (empty($list)) {
            $legacy = mh_custom_notices_read_legacy($dataBase);
            if (!empty($legacy)) {
                $cfg = ['custom_messages' => $legacy];
                $list = $legacy;
                mh_custom_notices_write($dataBase, $filePath, $cfg);
            }
        }
        $title = 'Culture Coins Reservation';
        $has = false;
        $now = time();
        foreach ($list as $m) {
            if (!is_array($m)) continue;
            $t = isset($m['title']) ? trim((string)$m['title']) : '';
            if (strcasecmp($t, $title) !== 0) continue;
            $st = isset($m['status']) ? strtolower(trim((string)$m['status'])) : 'active';
            $arch = isset($m['archived_at']) ? trim((string)$m['archived_at']) : '';
            $exp = isset($m['expires_at']) ? trim((string)$m['expires_at']) : '';
            if ($st === 'archived' || $arch !== '') continue;
            if ($exp !== '') {
                $tExp = strtotime($exp);
                if ($tExp && $tExp <= $now) continue;
            }
            $has = true;
            break;
        }

        if (!$has) {
            $body = "Culture coins reservation is open.\n\nOpen the Culture Coins page for details.";
            try {
                if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_seed_culture_coins') && function_exists('mh_tokenomics_get_current_price_usd')) {
                    $pdoTok = mh_tokenomics_get_tokenomics_pdo();
                    if ($pdoTok instanceof PDO) {
                        $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $ids = mh_tokenomics_seed_culture_coins($pdoTok);
                        $champId = (int)($ids['champcoin'] ?? 0);
                        $superId = (int)($ids['supercoin'] ?? 0);
                        $readMeta = function (int $id) use ($pdoTok): array {
                            $stmt = $pdoTok->prepare("SELECT display_name, pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                            $stmt->execute([$id]);
                            $row = $stmt->fetch(PDO::FETCH_ASSOC);
                            $out = ['name' => '', 'ticker' => '', 'cap' => 0, 'issue' => '', 'close' => ''];
                            if (is_array($row) && !empty($row)) {
                                $out['name'] = trim((string)($row['display_name'] ?? ''));
                                $raw = trim((string)($row['pricing_params_json'] ?? ''));
                                $meta = $raw !== '' ? json_decode($raw, true) : null;
                                if (is_array($meta)) {
                                    $out['ticker'] = isset($meta['ticker']) ? trim((string)$meta['ticker']) : '';
                                    $out['cap'] = isset($meta['supply_cap']) ? (int)$meta['supply_cap'] : 0;
                                    $out['issue'] = isset($meta['issue_date']) ? trim((string)$meta['issue_date']) : '';
                                    $out['close'] = isset($meta['close_date']) ? trim((string)$meta['close_date']) : '';
                                }
                            }
                            return $out;
                        };
                        $champ = ['name' => 'Champion Coin', 'ticker' => 'mhc', 'cap' => 0, 'issue' => '', 'close' => ''];
                        $super = ['name' => 'Super Coin', 'ticker' => 'mhs', 'cap' => 0, 'issue' => '', 'close' => ''];
                        if ($champId > 0) {
                            $m = $readMeta($champId);
                            if ($m['name'] !== '') $champ['name'] = $m['name'];
                            if ($m['ticker'] !== '') $champ['ticker'] = $m['ticker'];
                            $champ['cap'] = (int)($m['cap'] ?? 0);
                            $champ['issue'] = (string)($m['issue'] ?? '');
                            $champ['close'] = (string)($m['close'] ?? '');
                        }
                        if ($superId > 0) {
                            $m = $readMeta($superId);
                            if ($m['name'] !== '') $super['name'] = $m['name'];
                            if ($m['ticker'] !== '') $super['ticker'] = $m['ticker'];
                            $super['cap'] = (int)($m['cap'] ?? 0);
                            $super['issue'] = (string)($m['issue'] ?? '');
                            $super['close'] = (string)($m['close'] ?? '');
                        }
                        $champPrice = $champId > 0 ? mh_tokenomics_get_current_price_usd($pdoTok, $champId) : null;
                        $superPrice = $superId > 0 ? mh_tokenomics_get_current_price_usd($pdoTok, $superId) : null;
                        $lines = [];
                        $lines[] = 'Open: /hub/coins/culture.php';
                        $lines[] = '';
                        $lines[] = $champ['name'] . ' (' . strtoupper($champ['ticker']) . ')';
                        if (is_float($champPrice) && $champPrice > 0) $lines[] = 'Price: $' . number_format((float)$champPrice, 2, '.', '') . ' per coin';
                        if ($champ['issue'] !== '') $lines[] = 'Issue date: ' . $champ['issue'];
                        if ($champ['close'] !== '') $lines[] = 'Close date: ' . $champ['close'];
                        if ((int)$champ['cap'] > 0) $lines[] = 'Supply cap: ' . number_format((int)$champ['cap']);
                        $lines[] = '';
                        $lines[] = $super['name'] . ' (' . strtoupper($super['ticker']) . ')';
                        if (is_float($superPrice) && $superPrice > 0) $lines[] = 'Current price: $' . number_format((float)$superPrice, 2, '.', '') . ' per coin';
                        if ($super['issue'] !== '') $lines[] = 'Issue date: ' . $super['issue'];
                        if ($super['close'] !== '') $lines[] = 'Close date: ' . $super['close'];
                        if ((int)$super['cap'] > 0) $lines[] = 'Supply cap: ' . number_format((int)$super['cap']);
                        $body = implode("\n", $lines);
                    }
                }
            } catch (Throwable) {}

            array_unshift($list, [
                'id' => 'n_' . bin2hex(random_bytes(8)),
                'title' => $title,
                'body' => $body,
                'url' => '/hub/coins/culture.php',
                'type' => 'info',
                'pinned' => true,
                'created_at' => mh_custom_notices_now(),
                'expires_at' => '',
                'status' => 'active',
            ]);
            $cfg['custom_messages'] = $list;
            mh_custom_notices_write($dataBase, $filePath, $cfg);
        }
    } catch (Throwable) {}
    header('Location: /gear/coms/custom-notices.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $raw = isset($_POST['custom_messages_json']) ? trim((string)$_POST['custom_messages_json']) : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        $decoded = is_array($decoded) ? $decoded : [];
        $clean = [];
        foreach ($decoded as $m) {
            if (!is_array($m)) continue;
            $id = isset($m['id']) ? trim((string)$m['id']) : '';
            if ($id === '') $id = 'n_' . bin2hex(random_bytes(8));
            if (strlen($id) > 128) continue;
            $title = isset($m['title']) ? trim((string)$m['title']) : '';
            $body = isset($m['body']) ? trim((string)$m['body']) : '';
            $url = isset($m['url']) ? trim((string)$m['url']) : '';
            $type = isset($m['type']) ? trim((string)$m['type']) : 'info';
            $pinned = !empty($m['pinned']);
            $createdAt = isset($m['created_at']) ? trim((string)$m['created_at']) : '';
            $expiresAt = isset($m['expires_at']) ? trim((string)$m['expires_at']) : '';
            $archivedAt = isset($m['archived_at']) ? trim((string)$m['archived_at']) : '';
            $status = isset($m['status']) ? strtolower(trim((string)$m['status'])) : 'active';

            if (!in_array($type, ['info', 'success', 'warning', 'error'], true)) $type = 'info';
            if (!in_array($status, ['active', 'archived', 'expired'], true)) $status = 'active';
            $createdAt = $createdAt !== '' ? mh_custom_notices_norm_dt($createdAt) : '';
            if ($createdAt === '' && ($title !== '' || $body !== '' || $url !== '')) $createdAt = mh_custom_notices_now();
            $expiresAt = $expiresAt !== '' ? mh_custom_notices_norm_dt($expiresAt) : '';
            $archivedAt = $archivedAt !== '' ? mh_custom_notices_norm_dt($archivedAt) : '';
            if ($status === 'archived' && $archivedAt === '') $archivedAt = mh_custom_notices_now();

            if ($title === '' && $body === '' && $url === '') continue;
            if ($url !== '' && stripos($url, 'javascript:') === 0) $url = '';
            $entry = [
                'id' => $id,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'type' => $type,
                'pinned' => $pinned,
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'status' => $status,
            ];
            if ($archivedAt !== '') $entry['archived_at'] = $archivedAt;
            $clean[] = $entry;
        }

        $cfg = ['custom_messages' => $clean];
        if (!mh_custom_notices_write($dataBase, $filePath, $cfg)) {
            throw new RuntimeException('Failed to save');
        }
        $message = 'Saved';
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}

$cfg = mh_custom_notices_read($filePath);
$custom = isset($cfg['custom_messages']) && is_array($cfg['custom_messages']) ? $cfg['custom_messages'] : [];
if (empty($custom)) {
    $legacy = mh_custom_notices_read_legacy($dataBase);
    if (!empty($legacy)) {
        $cfg = ['custom_messages' => $legacy];
        $custom = $legacy;
        mh_custom_notices_write($dataBase, $filePath, $cfg);
    }
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Notices</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        body { background: #0b0f1a; color: rgba(255,255,255,0.92); font-family: 'Rajdhani', sans-serif; min-height: 100vh; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 24px 18px 50px; }
        h1 { font-family: 'Orbitron', sans-serif; color: #00d4ff; margin: 0 0 10px; }
        .sub { color: rgba(255,255,255,0.70); margin-bottom: 18px; }
        .card { background: rgba(255,255,255,0.05); border: 1px solid rgba(0,212,255,0.18); border-radius: 14px; padding: 16px; overflow: hidden; }
        .row { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; }
        .btn { display:inline-flex; align-items:center; justify-content:center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.30); background: rgba(0,0,0,0.20); color: rgba(255,255,255,0.92); text-decoration:none; font-weight: 900; cursor:pointer; }
        .btn.primary { background: rgba(0,212,255,0.18); }
        .btn.danger { border-color: rgba(255,80,80,0.45); background: rgba(255,80,80,0.10); }
        .btn.warn { border-color: rgba(245,158,11,0.45); background: rgba(245,158,11,0.10); }
        .btn.ok { border-color: rgba(16,185,129,0.45); background: rgba(16,185,129,0.10); }
        .badge { display:inline-flex; padding: 4px 10px; border-radius: 999px; font-weight: 900; letter-spacing: 1px; font-size: 12px; border: 1px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.18); }
        .muted { color: rgba(255,255,255,0.65); font-size: 12px; }
        .list { margin-top: 12px; display:flex; flex-direction: column; gap: 12px; }
        .item { background: rgba(0,0,0,0.20); border: 1px solid rgba(255,255,255,0.10); border-radius: 14px; padding: 14px; }
        .item-head { display:flex; gap: 10px; align-items:center; justify-content:space-between; flex-wrap:wrap; }
        .item-title { font-weight: 900; }
        .editor { margin-top: 12px; border-top: 1px solid rgba(255,255,255,0.10); padding-top: 12px; display:none; }
        label { display:block; font-weight: 900; margin-bottom: 6px; color: rgba(255,255,255,0.82); }
        input, textarea, select { width: 100%; background: rgba(0,0,0,0.35); border: 1px solid rgba(0,212,255,0.20); color: rgba(255,255,255,0.92); padding: 10px 12px; border-radius: 10px; }
        textarea { resize: vertical; }
        .grid2 { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 820px) { .grid2 { grid-template-columns: 1fr; } }
        .alert { margin-top: 12px; padding: 12px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.30); background: rgba(0,212,255,0.08); color: rgba(255,255,255,0.92); }
        .alert.err { border-color: rgba(255,80,80,.55); background: rgba(255,80,80,.10); }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="wrap">
    <h1>CUSTOM NOTICES</h1>
    <div class="sub">Manage custom notices shown in the Hub notices panel.</div>
    <div class="card">
        <div class="row">
            <button type="button" class="btn primary" id="addBtn">+ Notice</button>
            <a class="btn" href="/hub/notices.php">All Notices</a>
            <a class="btn" href="/hub/notice-archived.php">Archive</a>
            <div class="muted" id="count" style="margin-left:auto;"></div>
        </div>
        <?php if ($message !== ''): ?><div class="alert"><?php echo h($message); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert err"><?php echo h($error); ?></div><?php endif; ?>
        <form method="POST" action="/gear/coms/custom-notices.php" id="form">
            <input type="hidden" name="custom_messages_json" id="payload" value="">
            <div class="list" id="list"></div>
            <div style="margin-top: 14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="submit" class="btn ok">Save</button>
            </div>
        </form>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<script>
    (function () {
        const initial = <?php echo json_encode($custom, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const listEl = document.getElementById("list");
        const addBtn = document.getElementById("addBtn");
        const countEl = document.getElementById("count");
        const payloadEl = document.getElementById("payload");
        const form = document.getElementById("form");
        const types = ["info", "success", "warning", "error"];
        const statuses = ["active", "archived", "expired"];
        let items = Array.isArray(initial) ? initial : [];

        function nowSql() {
            const d = new Date();
            const pad = (n) => String(n).padStart(2, "0");
            return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()) + " " + pad(d.getHours()) + ":" + pad(d.getMinutes()) + ":" + pad(d.getSeconds());
        }

        function ensureId(n) {
            if (!n.id || typeof n.id !== "string" || !n.id.trim()) n.id = "n_" + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
            return n;
        }

        function toInputDt(sql) {
            if (!sql) return "";
            const s = String(sql).trim();
            if (s.length < 16) return "";
            return s.replace(" ", "T").slice(0, 16);
        }

        function fromInputDt(v) {
            const s = String(v || "").trim();
            if (!s) return "";
            if (s.indexOf("T") !== -1) return s.replace("T", " ") + ":00";
            return s;
        }

        function isExpired(n) {
            const exp = (n.expires_at || "").trim();
            if (!exp) return false;
            const t = Date.parse(exp.replace(" ", "T") + "Z");
            if (!isFinite(t)) return false;
            return t <= Date.now();
        }

        function serialize() {
            const cleaned = [];
            for (let i = 0; i < items.length; i++) {
                const n = items[i];
                if (!n || typeof n !== "object") continue;
                ensureId(n);
                const t = (n.title || "").trim();
                const b = (n.body || "").trim();
                const u = (n.url || "").trim();
                if (!t && !b && !u) continue;
                const ty = types.includes(n.type) ? n.type : "info";
                const st = statuses.includes(n.status) ? n.status : "active";
                const out = {
                    id: String(n.id || "").trim(),
                    title: t,
                    body: b,
                    url: u,
                    type: ty,
                    pinned: !!n.pinned,
                    created_at: (n.created_at || "").trim() || nowSql(),
                    expires_at: (n.expires_at || "").trim(),
                    status: st
                };
                if ((n.archived_at || "").trim()) out.archived_at = (n.archived_at || "").trim();
                cleaned.push(out);
            }
            payloadEl.value = JSON.stringify(cleaned);
            return cleaned;
        }

        function badge(txt) {
            const s = document.createElement("span");
            s.className = "badge";
            s.textContent = txt;
            return s;
        }

        function render() {
            serialize();
            listEl.innerHTML = "";
            const activeCount = items.filter(n => n && typeof n === "object" && (String(n.status || "active").toLowerCase() === "active") && !((n.archived_at || "").trim()) && !isExpired(n)).length;
            countEl.textContent = activeCount + " active";
            if (!items.length) {
                const empty = document.createElement("div");
                empty.className = "item";
                empty.innerHTML = '<div class="muted">No notices yet.</div>';
                listEl.appendChild(empty);
                return;
            }

            for (let i = 0; i < items.length; i++) {
                const n = items[i];
                if (!n || typeof n !== "object") continue;
                ensureId(n);

                const wrap = document.createElement("div");
                wrap.className = "item";

                const head = document.createElement("div");
                head.className = "item-head";

                const left = document.createElement("div");
                left.style.display = "flex";
                left.style.flexDirection = "column";
                left.style.gap = "6px";

                const title = document.createElement("div");
                title.className = "item-title";
                title.textContent = (n.title || "").trim() || "Untitled notice";

                const meta = document.createElement("div");
                meta.style.display = "flex";
                meta.style.gap = "8px";
                meta.style.flexWrap = "wrap";

                const st = String(n.status || "active").toLowerCase();
                const arch = (n.archived_at || "").trim();
                if (st === "archived" || arch) meta.appendChild(badge("ARCHIVED"));
                else if (isExpired(n) || st === "expired") meta.appendChild(badge("EXPIRED"));
                else meta.appendChild(badge("ACTIVE"));
                meta.appendChild(badge(String(n.type || "info").toUpperCase()));
                if (n.pinned) meta.appendChild(badge("PINNED"));

                const created = document.createElement("div");
                created.className = "muted";
                created.textContent = "Created: " + ((n.created_at || "").trim() || "-");

                left.appendChild(title);
                left.appendChild(meta);
                left.appendChild(created);

                const right = document.createElement("div");
                right.style.display = "flex";
                right.style.gap = "8px";
                right.style.flexWrap = "wrap";

                const editBtn = document.createElement("button");
                editBtn.type = "button";
                editBtn.className = "btn";
                editBtn.textContent = "Edit";

                const archiveBtn = document.createElement("button");
                archiveBtn.type = "button";
                archiveBtn.className = "btn warn";
                archiveBtn.textContent = (st === "archived" || arch) ? "Unarchive" : "Archive";

                const expireBtn = document.createElement("button");
                expireBtn.type = "button";
                expireBtn.className = "btn";
                expireBtn.textContent = "Expire";

                const delBtn = document.createElement("button");
                delBtn.type = "button";
                delBtn.className = "btn danger";
                delBtn.textContent = "Delete";

                right.appendChild(editBtn);
                right.appendChild(archiveBtn);
                right.appendChild(expireBtn);
                right.appendChild(delBtn);

                head.appendChild(left);
                head.appendChild(right);

                const editor = document.createElement("div");
                editor.className = "editor";

                function row(label, el) {
                    const g = document.createElement("div");
                    g.style.marginBottom = "12px";
                    const l = document.createElement("label");
                    l.textContent = label;
                    g.appendChild(l);
                    g.appendChild(el);
                    return g;
                }

                const inTitle = document.createElement("input");
                inTitle.type = "text";
                inTitle.value = (n.title || "").trim();

                const inBody = document.createElement("textarea");
                inBody.rows = 5;
                inBody.value = (n.body || "").trim();

                const inUrl = document.createElement("input");
                inUrl.type = "text";
                inUrl.value = (n.url || "").trim();

                const inType = document.createElement("select");
                for (let k = 0; k < types.length; k++) {
                    const opt = document.createElement("option");
                    opt.value = types[k];
                    opt.textContent = types[k];
                    if (String(n.type || "info") === types[k]) opt.selected = true;
                    inType.appendChild(opt);
                }

                const pinWrap = document.createElement("div");
                pinWrap.style.display = "flex";
                pinWrap.style.gap = "10px";
                pinWrap.style.alignItems = "center";
                const inPinned = document.createElement("input");
                inPinned.type = "checkbox";
                inPinned.checked = !!n.pinned;
                const pinLbl = document.createElement("label");
                pinLbl.textContent = "Pinned";
                pinLbl.style.margin = "0";
                pinWrap.appendChild(inPinned);
                pinWrap.appendChild(pinLbl);

                const inExpires = document.createElement("input");
                inExpires.type = "datetime-local";
                inExpires.value = toInputDt((n.expires_at || "").trim());

                const inStatus = document.createElement("select");
                for (let k = 0; k < statuses.length; k++) {
                    const opt = document.createElement("option");
                    opt.value = statuses[k];
                    opt.textContent = statuses[k];
                    if (String(n.status || "active") === statuses[k]) opt.selected = true;
                    inStatus.appendChild(opt);
                }

                const g2 = document.createElement("div");
                g2.className = "grid2";
                g2.appendChild(row("Type", inType));
                g2.appendChild(row("Status", inStatus));

                editor.appendChild(row("Title", inTitle));
                editor.appendChild(row("Body", inBody));
                editor.appendChild(row("URL", inUrl));
                editor.appendChild(g2);
                editor.appendChild(pinWrap);
                editor.appendChild(row("Expires at (optional)", inExpires));

                const actions = document.createElement("div");
                actions.className = "row";
                const saveBtn = document.createElement("button");
                saveBtn.type = "button";
                saveBtn.className = "btn ok";
                saveBtn.textContent = "Apply";
                const closeBtn = document.createElement("button");
                closeBtn.type = "button";
                closeBtn.className = "btn";
                closeBtn.textContent = "Close";
                actions.appendChild(saveBtn);
                actions.appendChild(closeBtn);
                editor.appendChild(actions);

                editBtn.addEventListener("click", function () {
                    editor.style.display = (editor.style.display === "none" || editor.style.display === "") ? "block" : "none";
                });
                closeBtn.addEventListener("click", function () {
                    editor.style.display = "none";
                });
                saveBtn.addEventListener("click", function () {
                    n.title = inTitle.value;
                    n.body = inBody.value;
                    n.url = inUrl.value;
                    n.type = inType.value;
                    n.pinned = inPinned.checked;
                    n.expires_at = fromInputDt(inExpires.value);
                    n.status = inStatus.value;
                    if (!n.created_at) n.created_at = nowSql();
                    if (String(n.status || "").toLowerCase() === "archived" && !(n.archived_at || "").trim()) n.archived_at = nowSql();
                    if (String(n.status || "").toLowerCase() !== "archived") n.archived_at = "";
                    render();
                });
                archiveBtn.addEventListener("click", function () {
                    const cur = String(n.status || "active").toLowerCase();
                    const archAt = (n.archived_at || "").trim();
                    if (cur === "archived" || archAt) {
                        n.status = "active";
                        n.archived_at = "";
                    } else {
                        n.status = "archived";
                        n.archived_at = nowSql();
                    }
                    render();
                });
                expireBtn.addEventListener("click", function () {
                    n.status = "expired";
                    n.expires_at = nowSql();
                    render();
                });
                delBtn.addEventListener("click", function () {
                    items = items.filter(x => x && x.id !== n.id);
                    render();
                });

                wrap.appendChild(head);
                wrap.appendChild(editor);
                listEl.appendChild(wrap);
            }
        }

        addBtn.addEventListener("click", function () {
            items.unshift({ id: "n_" + Math.random().toString(16).slice(2), title: "", body: "", url: "", type: "info", pinned: false, created_at: nowSql(), expires_at: "", status: "active" });
            render();
            const first = listEl.querySelector("button.btn");
            if (first) first.click();
        });

        if (form) form.addEventListener("submit", function () { serialize(); });
        render();
    })();
</script>
</body>
</html>
