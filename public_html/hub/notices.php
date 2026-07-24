<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/.cue/cue.php';
require_once dirname(__DIR__) . '/auth/auth_functions.php';
if (is_file(dirname(__DIR__) . '/auth/tokenomics.php')) {
    require_once dirname(__DIR__) . '/auth/tokenomics.php';
}
require_once __DIR__ . '/benefactors/lib.php';
if (is_file(__DIR__ . '/equity/db.php')) {
    require_once __DIR__ . '/equity/db.php';
}

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$ajax = isset($_GET['ajax']) ? trim((string)$_GET['ajax']) : '';
$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';

if ($ajax !== '') {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

if ($username === '') {
    if ($ajax !== '') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $redirect = '/hub/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

function mh_notices_data_path(): string
{
    $p = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $p = $p !== '' ? rtrim($p, '/') : '/data';
    return $p;
}

function mh_notices_cfg_path(): string
{
    $dataPath = mh_notices_data_path();
    return $dataPath . '/widgets/notices/widgets-config.json';
}

function mh_notices_custom_cfg_path(): string
{
    $dataPath = mh_notices_data_path();
    return $dataPath . '/widgets/notices/custom-notices.json';
}

function mh_notices_safe_cfg_path(): ?string
{
    $p = mh_notices_cfg_path();
    $base = mh_notices_data_path();
    try {
        if (function_exists('cue_autoload')) {
            $paths = cue_autoload('paths');
            if (is_object($paths) && method_exists($paths, 'validateSecurePath')) {
                $safe = $paths->validateSecurePath($p, $base);
                if (is_string($safe) && $safe !== '') return $safe;
            }
        }
    } catch (Throwable) {}
    return $p;
}

function mh_notices_safe_custom_cfg_path(): ?string
{
    $p = mh_notices_custom_cfg_path();
    $base = mh_notices_data_path();
    try {
        if (function_exists('cue_autoload')) {
            $paths = cue_autoload('paths');
            if (is_object($paths) && method_exists($paths, 'validateSecurePath')) {
                $safe = $paths->validateSecurePath($p, $base);
                if (is_string($safe) && $safe !== '') return $safe;
            }
        }
    } catch (Throwable) {}
    return $p;
}

function mh_notices_read_config(): array
{
    $safe = mh_notices_safe_cfg_path();
    if (!is_string($safe) || $safe === '' || !is_file($safe) || !is_readable($safe)) return [];
    $raw = @file_get_contents($safe);
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function mh_notices_read_custom_config(): array
{
    $safe = mh_notices_safe_custom_cfg_path();
    if (!is_string($safe) || $safe === '' || !is_file($safe) || !is_readable($safe)) return [];
    $raw = @file_get_contents($safe);
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function mh_notices_write_custom_config(array $cfg): bool
{
    $safe = mh_notices_safe_custom_cfg_path();
    if (!is_string($safe) || $safe === '') return false;
    $dir = dirname($safe);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) return false;
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;
    return @file_put_contents($safe, $json . "\n") !== false;
}

function mh_notices_upload_dir(): string
{
    $data = mh_notices_data_path();
    $tenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($tenantId === '' && $u !== '') $tenantId = 'user:' . $u;
    $tenantSafe = preg_replace('/[^a-zA-Z0-9_\\-:\\.]+/', '_', (string)$tenantId);
    $tenantSafe = is_string($tenantSafe) ? trim($tenantSafe) : '';
    if ($tenantSafe === '') $tenantSafe = 'user_unknown';
    $tenantDir = $data . '/tenants/' . $tenantSafe . '/widgets/notices/uploads';
    if (is_dir($tenantDir)) return $tenantDir;
    return $data . '/widgets/notices/uploads';
}

function mh_notices_attachment_lookup(string $fileId): ?array
{
    $fileId = trim($fileId);
    if ($fileId === '') return null;
    $cfgGear = mh_notices_read_custom_config();
    $cfgWidgets = mh_notices_read_config();
    $sources = [];
    $sources[] = isset($cfgGear['custom_messages']) && is_array($cfgGear['custom_messages']) ? $cfgGear['custom_messages'] : [];
    $sources[] = isset($cfgWidgets['custom_messages']) && is_array($cfgWidgets['custom_messages']) ? $cfgWidgets['custom_messages'] : [];
    foreach ($sources as $custom) {
        if (!is_array($custom) || empty($custom)) continue;
        foreach ($custom as $m) {
            if (!is_array($m)) continue;
            $att = $m['attachment'] ?? null;
            if (!is_array($att)) continue;
            $fid = isset($att['file_id']) ? trim((string)$att['file_id']) : '';
            if ($fid === '' || !hash_equals($fid, $fileId)) continue;
            $stored = isset($att['stored']) ? trim((string)$att['stored']) : '';
            $name = isset($att['name']) ? trim((string)$att['name']) : '';
            $ext = isset($att['ext']) ? strtolower(trim((string)$att['ext'])) : '';
            $mime = isset($att['mime']) ? trim((string)$att['mime']) : '';
            if ($stored === '') return null;
            $dir = mh_notices_upload_dir();
            $path = $dir . '/' . basename($stored);
            $rpDir = realpath($dir);
            $rpPath = realpath($path);
            if (!is_string($rpDir) || $rpDir === '' || !is_string($rpPath) || $rpPath === '') return null;
            if (strpos($rpPath, $rpDir) !== 0) return null;
            if (!is_file($rpPath) || !is_readable($rpPath)) return null;
            return [
                'file_id' => $fid,
                'path' => $rpPath,
                'name' => $name !== '' ? $name : basename($rpPath),
                'ext' => $ext,
                'mime' => $mime,
            ];
        }
    }
    return null;
}

function mh_notices_parse_ts(?string $dt): int
{
    $dt = trim((string)$dt);
    if ($dt === '') return 0;
    $t = strtotime($dt);
    return $t ? (int)$t : 0;
}

function mh_notices_make_id(string $prefix, int $ts, string $salt): string
{
    return $prefix . ':' . substr(hash('sha256', $prefix . '|' . $ts . '|' . $salt), 0, 20);
}

function mh_notices_get_champ_remaining(?PDO $pdoTok): ?int
{
    if (!$pdoTok) return null;
    if (!function_exists('mh_tokenomics_seed_culture_coins')) return null;
    $ids = mh_tokenomics_seed_culture_coins($pdoTok);
    $champId = (int)($ids['champcoin'] ?? 0);
    if ($champId < 1) return null;
    try {
        $cachedTs = session_status() === PHP_SESSION_ACTIVE ? (int)($_SESSION['mh_champ_remaining_ts'] ?? 0) : 0;
        $cachedVal = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION['mh_champ_remaining_val'] ?? null) : null;
        if ($cachedTs > 0 && (time() - $cachedTs) < 60 && is_int($cachedVal)) {
            return $cachedVal;
        }
        $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM mh_asset_ledger WHERE asset_class_id = ?");
        $stmt->execute([$champId]);
        $issued = (int)$stmt->fetchColumn();
        $remaining = max(0, 1000000 - max(0, $issued));
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['mh_champ_remaining_ts'] = time();
            $_SESSION['mh_champ_remaining_val'] = $remaining;
        }
        return $remaining;
    } catch (Throwable) {
        return null;
    }
}

function mh_notices_ensure_dismiss_schema(PDO $pdoTok): void
{
    try {
        $pdoTok->exec("
            CREATE TABLE IF NOT EXISTS mh_notice_dismissals (
                username VARCHAR(64) NOT NULL,
                notice_id VARCHAR(128) NOT NULL,
                dismissed_at DATETIME NOT NULL,
                PRIMARY KEY (username, notice_id),
                KEY idx_notice_id (notice_id),
                KEY idx_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable) {}
}

function mh_notices_get_dismissed(PDO $pdoTok, string $username): array
{
    $username = trim($username);
    if ($username === '') return [];
    mh_notices_ensure_dismiss_schema($pdoTok);
    try {
        $stmt = $pdoTok->prepare("SELECT notice_id, dismissed_at FROM mh_notice_dismissals WHERE username = ?");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $id = isset($r['notice_id']) ? trim((string)$r['notice_id']) : '';
            if ($id === '') continue;
            $out[$id] = isset($r['dismissed_at']) ? trim((string)$r['dismissed_at']) : '';
        }
        return $out;
    } catch (Throwable) {
        return [];
    }
}

function mh_notices_set_dismissed(PDO $pdoTok, string $username, string $noticeId): bool
{
    $username = trim($username);
    $noticeId = trim($noticeId);
    if ($username === '' || $noticeId === '') return false;
    if (strlen($noticeId) > 128) return false;
    mh_notices_ensure_dismiss_schema($pdoTok);
    try {
        $stmt = $pdoTok->prepare("INSERT INTO mh_notice_dismissals (username, notice_id, dismissed_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE dismissed_at = dismissed_at");
        $stmt->execute([$username, $noticeId]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function mh_notices_ensure_event_schema(PDO $pdoTok): void
{
    try {
        $pdoTok->exec("
            CREATE TABLE IF NOT EXISTS mh_notice_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(64) NOT NULL,
                notice_id VARCHAR(128) NOT NULL,
                event_type VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                meta_json JSON NULL,
                PRIMARY KEY (id),
                KEY idx_user_notice (username, notice_id),
                KEY idx_user_type (username, event_type),
                KEY idx_notice (notice_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable) {}
}

function mh_notices_add_event(PDO $pdoTok, string $username, string $noticeId, string $eventType, array $meta = []): bool
{
    $username = trim($username);
    $noticeId = trim($noticeId);
    $eventType = strtolower(trim($eventType));
    if ($username === '' || $noticeId === '') return false;
    if (strlen($noticeId) > 128) return false;
    if (!in_array($eventType, ['view', 'read', 'dismiss'], true)) return false;
    mh_notices_ensure_event_schema($pdoTok);
    try {
        $mj = null;
        if (!empty($meta)) {
            $enc = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($enc) && $enc !== '' && $enc !== 'null') $mj = $enc;
        }
        $stmt = $pdoTok->prepare("INSERT INTO mh_notice_events (username, notice_id, event_type, created_at, meta_json) VALUES (?, ?, ?, NOW(), ?)"); 
        $stmt->execute([$username, $noticeId, $eventType, $mj]);
        return true;
    } catch (Throwable) {
        return false;
    }
}

function mh_notices_get_events_map(PDO $pdoTok, string $username): array
{
    $username = trim($username);
    if ($username === '') return [];
    mh_notices_ensure_event_schema($pdoTok);
    try {
        $stmt = $pdoTok->prepare("SELECT notice_id, event_type, created_at FROM mh_notice_events WHERE username = ? ORDER BY id ASC");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $nid = isset($r['notice_id']) ? trim((string)$r['notice_id']) : '';
            $et = isset($r['event_type']) ? strtolower(trim((string)$r['event_type'])) : '';
            $ca = isset($r['created_at']) ? trim((string)$r['created_at']) : '';
            if ($nid === '' || $et === '' || $ca === '') continue;
            if (!isset($map[$nid])) $map[$nid] = [];
            if (!isset($map[$nid][$et])) $map[$nid][$et] = [];
            $map[$nid][$et][] = $ca;
        }
        return $map;
    } catch (Throwable) {
        return [];
    }
}

function mh_notices_collect_feed(string $username, bool $applyLimit, bool $filterDismissed): array
{
    $cfg = mh_notices_read_config();
    $panel = isset($cfg['panel']) && is_array($cfg['panel']) ? $cfg['panel'] : [];
    $panelCfg = [
        'enabled' => array_key_exists('enabled', $panel) ? !empty($panel['enabled']) : true,
        'auto_open_seconds' => isset($panel['auto_open_seconds']) ? max(0, min(300, (int)$panel['auto_open_seconds'])) : 15,
        'layout' => (isset($panel['layout']) && in_array((string)$panel['layout'], ['right', 'left'], true)) ? (string)$panel['layout'] : 'right',
        'enable_animation' => array_key_exists('enable_animation', $panel) ? !empty($panel['enable_animation']) : true,
        'icon_flicker_on_new' => array_key_exists('icon_flicker_on_new', $panel) ? !empty($panel['icon_flicker_on_new']) : true,
    ];

    $notices = [];
    $maxTs = 0;
    $add = function (array $n) use (&$notices, &$maxTs): void {
        $ts = isset($n['ts']) ? (int)$n['ts'] : 0;
        if ($ts > $maxTs) $maxTs = $ts;
        $notices[] = $n;
    };

    $pdoTok = null;
    $dismissed = [];
    try {
        if (function_exists('mh_tokenomics_get_tokenomics_pdo')) {
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            if ($pdoTok instanceof PDO) {
                $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $dismissed = mh_notices_get_dismissed($pdoTok, $username);
            }
        }
    } catch (Throwable) {
        $pdoTok = null;
        $dismissed = [];
    }

    try {
        $pdo = mh_benefactors_pdo();
        $stmt = $pdo->prepare("SELECT id, owner_username, status, created_at FROM benefactors WHERE benefactor_username = ? AND status IN ('pending','active') ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $status = strtolower(trim((string)($r['status'] ?? 'pending')));
            $created = (string)($r['created_at'] ?? '');
            $ts = mh_notices_parse_ts($created);
            $owner = (string)($r['owner_username'] ?? '');
            $id = mh_notices_make_id('benefactor_appointment', $ts, $owner . '|' . $status);
            $add([
                'id' => $id,
                'ts' => $ts,
                'type' => $status === 'pending' ? 'warning' : 'info',
                'title' => 'You were added as a benefactor',
                'body' => ($owner !== '' ? ('Owner: ' . $owner . ".\n") : '') . 'Status: ' . strtoupper($status) . '.',
                'url' => '/hub/equity/benefactors.php',
                'pinned' => ($status === 'pending'),
            ]);
        }
    } catch (Throwable) {}

    try {
        if ($pdoTok instanceof PDO) {
            $stmt = $pdoTok->prepare("SELECT created_at, units, meta_json FROM mh_asset_transactions WHERE username = ? AND direction = 'credit' AND service_key = 'transfer:peer' ORDER BY id DESC LIMIT 10");
            $stmt->execute([$username]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $created = (string)($r['created_at'] ?? '');
                $ts = mh_notices_parse_ts($created);
                $units = (int)($r['units'] ?? 0);
                $metaRaw = (string)($r['meta_json'] ?? '');
                $meta = $metaRaw !== '' ? (json_decode($metaRaw, true) ?: []) : [];
                $from = is_array($meta) && isset($meta['from']) ? trim((string)$meta['from']) : '';
                $id = mh_notices_make_id('token_credit', $ts, $from . '|' . $units);
                $add([
                    'id' => $id,
                    'ts' => $ts,
                    'type' => 'success',
                    'title' => 'Tokens received',
                    'body' => number_format($units) . ' MTK received' . ($from !== '' ? (' from ' . $from) : '') . '.',
                    'url' => '/hub/tokens/tokens.php',
                    'pinned' => false,
                ]);
            }

            $stmt = $pdoTok->prepare("SELECT created_at, amount, requester_username FROM mh_token_transfer_requests WHERE payer_username = ? AND status = 'pending' ORDER BY id DESC LIMIT 10");
            $stmt->execute([$username]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $created = (string)($r['created_at'] ?? '');
                $ts = mh_notices_parse_ts($created);
                $amt = (int)($r['amount'] ?? 0);
                $req = (string)($r['requester_username'] ?? '');
                $id = mh_notices_make_id('token_request', $ts, $req . '|' . $amt);
                $add([
                    'id' => $id,
                    'ts' => $ts,
                    'type' => 'warning',
                    'title' => 'Token request awaiting your decision',
                    'body' => ($req !== '' ? ($req . ' requested ') : 'Requested ') . number_format($amt) . ' MTK.',
                    'url' => '/hub/tokens/tokens.php',
                    'pinned' => true,
                ]);
            }
        }
    } catch (Throwable) {}

    try {
        if (function_exists('getEquityConnectionStrict')) {
            $pdoEq = getEquityConnectionStrict();
            if ($pdoEq instanceof PDO) {
                $stmt = $pdoEq->prepare("
                    SELECT o.id, o.offer_type, o.qty, o.offered_price, o.updated_at, COALESCE(ap.accept_count, 0) AS accept_count
                    FROM equity_bid_offers o
                    LEFT JOIN (
                        SELECT offer_id, SUM(CASE WHEN LOWER(decision) = 'accept' THEN 1 ELSE 0 END) AS accept_count
                        FROM equity_bid_offer_approvals
                        GROUP BY offer_id
                    ) ap ON ap.offer_id = o.id
                    WHERE o.username = ? AND LOWER(o.status) = 'active' AND COALESCE(ap.accept_count, 0) > 0
                    ORDER BY o.updated_at DESC
                    LIMIT 10
                ");
                $stmt->execute([$username]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $r) {
                    $ts = mh_notices_parse_ts((string)($r['updated_at'] ?? ''));
                    $offerId = (int)($r['id'] ?? 0);
                    $acc = (int)($r['accept_count'] ?? 0);
                    $qty = (int)($r['qty'] ?? 0);
                    $ppu = (float)($r['offered_price'] ?? 0);
                    $id = mh_notices_make_id('bid_offer_approval', $ts, (string)$offerId . '|' . $acc);
                    $add([
                        'id' => $id,
                        'ts' => $ts,
                        'type' => 'info',
                        'title' => 'Bid/Offer approvals received',
                        'body' => 'Offer #' . $offerId . ': ' . $acc . "/2 approvals\nQty " . number_format($qty) . "\n$" . number_format($ppu, 2) . ' per unit.',
                        'url' => '/hub/equity/manage.php',
                        'pinned' => false,
                    ]);
                }

                $stmt = $pdoEq->prepare("SELECT id, units_available, price_per_unit, updated_at FROM equity_market WHERE seller_username = ? AND status = 'sold' ORDER BY updated_at DESC LIMIT 10");
                $stmt->execute([$username]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $r) {
                    $ts = mh_notices_parse_ts((string)($r['updated_at'] ?? ''));
                    $mid = (int)($r['id'] ?? 0);
                    $units = (int)($r['units_available'] ?? 0);
                    $ppu = (float)($r['price_per_unit'] ?? 0);
                    $id = mh_notices_make_id('market_sold', $ts, (string)$mid . '|' . $units);
                    $add([
                        'id' => $id,
                        'ts' => $ts,
                        'type' => 'success',
                        'title' => 'Your listing was purchased',
                        'body' => 'Market listing #' . $mid . "\n" . number_format($units) . ' units at $' . number_format($ppu, 2) . ' per unit.',
                        'url' => '/hub/equity/manage.php',
                        'pinned' => false,
                    ]);
                }
            }
        }
    } catch (Throwable) {}

    $customCfg = mh_notices_read_custom_config();
    $custom = isset($customCfg['custom_messages']) && is_array($customCfg['custom_messages']) ? $customCfg['custom_messages'] : [];
    if (empty($custom)) {
        $legacyCustom = isset($cfg['custom_messages']) && is_array($cfg['custom_messages']) ? $cfg['custom_messages'] : [];
        if (!empty($legacyCustom)) {
            mh_notices_write_custom_config(['custom_messages' => $legacyCustom]);
            $custom = $legacyCustom;
        }
    }
    foreach ($custom as $m) {
        if (!is_array($m)) continue;
        $status = isset($m['status']) ? strtolower(trim((string)$m['status'])) : 'active';
        $archivedAt = isset($m['archived_at']) ? trim((string)$m['archived_at']) : '';
        $expiresAt = isset($m['expires_at']) ? trim((string)$m['expires_at']) : '';
        if ($status === 'archived' || $archivedAt !== '') continue;
        if ($expiresAt !== '') {
            $expTs = mh_notices_parse_ts($expiresAt);
            if ($expTs > 0 && $expTs <= time()) continue;
        }
        $title = isset($m['title']) ? trim((string)$m['title']) : '';
        $body = isset($m['body']) ? trim((string)$m['body']) : '';
        $type = isset($m['type']) ? trim((string)$m['type']) : 'info';
        $url = isset($m['url']) ? trim((string)$m['url']) : '';
        $pinned = !empty($m['pinned']);
        $createdAt = isset($m['created_at']) ? trim((string)$m['created_at']) : '';
        $ts = mh_notices_parse_ts($createdAt);
        if ($title === '' && $body === '' && $url === '') continue;
        if (!in_array($type, ['info', 'success', 'warning', 'error'], true)) $type = 'info';
        if ($url !== '' && stripos($url, 'javascript:') === 0) $url = '';
        $id = isset($m['id']) ? trim((string)$m['id']) : '';
        if ($id === '' || strlen($id) > 128) {
            $id = mh_notices_make_id('custom', $ts, $title . '|' . $body . '|' . $url);
        }
        $payload = [
            'id' => $id,
            'ts' => $ts,
            'type' => $type,
            'title' => $title !== '' ? $title : 'Notice',
            'body' => $body,
            'url' => $url,
            'pinned' => $pinned,
        ];
        $att = $m['attachment'] ?? null;
        if (is_array($att)) {
            $fid = isset($att['file_id']) ? trim((string)$att['file_id']) : '';
            $nm = isset($att['name']) ? trim((string)$att['name']) : '';
            $ext = isset($att['ext']) ? strtolower(trim((string)$att['ext'])) : '';
            if ($fid !== '') {
                $payload['attachments'] = [[
                    'file_id' => $fid,
                    'name' => $nm !== '' ? $nm : $fid,
                    'ext' => $ext,
                    'preview_url' => '/hub/notices.php?ajax=preview&file_id=' . rawurlencode($fid),
                ]];
            }
        }
        $add($payload);
    }

    if ($filterDismissed && !empty($dismissed)) {
        $notices = array_values(array_filter($notices, function ($n) use ($dismissed) {
            $id = is_array($n) && isset($n['id']) ? trim((string)$n['id']) : '';
            if ($id === '') return true;
            return !array_key_exists($id, $dismissed);
        }));
    }

    if (empty($notices)) {
        $add([
            'id' => 'notices_empty',
            'ts' => 0,
            'type' => 'info',
            'title' => 'Notices',
            'body' => 'No notices yet.',
            'url' => '/hub/',
            'pinned' => false,
        ]);
    }

    usort($notices, function ($a, $b) {
        $ap = !empty($a['pinned']) ? 1 : 0;
        $bp = !empty($b['pinned']) ? 1 : 0;
        if ($ap !== $bp) return $bp <=> $ap;
        $at = (int)($a['ts'] ?? 0);
        $bt = (int)($b['ts'] ?? 0);
        return $bt <=> $at;
    });

    if ($applyLimit && count($notices) > 80) {
        $notices = array_slice($notices, 0, 80);
    }

    return [
        'cfg' => $cfg,
        'panel' => $panelCfg,
        'max_ts' => $maxTs,
        'notices' => $notices,
        'dismissed' => $dismissed,
    ];
}

if ($ajax === 'file') {
    $fileId = isset($_GET['file_id']) ? trim((string)$_GET['file_id']) : '';
    $download = isset($_GET['download']) ? (int)$_GET['download'] : 0;
    $att = mh_notices_attachment_lookup($fileId);
    if (!$att) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $path = (string)$att['path'];
    $name = (string)$att['name'];
    $ext = strtolower(trim((string)($att['ext'] ?? '')));
    $mime = trim((string)($att['mime'] ?? ''));
    if ($mime === '') {
        $mime = match ($ext) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain; charset=UTF-8',
            'md' => 'text/markdown; charset=UTF-8',
            default => 'application/octet-stream',
        };
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $disp = $download ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . (string)filesize($path));
    @readfile($path);
    exit;
}

if ($ajax === 'preview') {
    $fileId = isset($_GET['file_id']) ? trim((string)$_GET['file_id']) : '';
    $returnRaw = isset($_GET['r']) ? trim((string)$_GET['r']) : '';
    $att = mh_notices_attachment_lookup($fileId);
    if (!$att) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    $name = (string)$att['name'];
    $ext = strtolower(trim((string)($att['ext'] ?? '')));
    $fileUrl = '/hub/notices.php?ajax=file&file_id=' . rawurlencode($fileId);
    $dlUrl = '/hub/notices.php?ajax=file&download=1&file_id=' . rawurlencode($fileId);
    $backHref = '/hub/';
    if ($returnRaw !== '') {
        $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
        if (str_starts_with($returnRaw, '/')) {
            $backHref = $returnRaw;
        } else {
            $u = @parse_url($returnRaw);
            $uHost = is_array($u) && isset($u['host']) ? strtolower((string)$u['host']) : '';
            $uScheme = is_array($u) && isset($u['scheme']) ? strtolower((string)$u['scheme']) : '';
            if ($uHost !== '' && $host !== '' && $uHost === $host && ($uScheme === 'http' || $uScheme === 'https')) {
                $path = is_array($u) && isset($u['path']) ? (string)$u['path'] : '/';
                if ($path === '') $path = '/';
                $q = is_array($u) && isset($u['query']) && (string)$u['query'] !== '' ? ('?' . (string)$u['query']) : '';
                $f = is_array($u) && isset($u['fragment']) && (string)$u['fragment'] !== '' ? ('#' . (string)$u['fragment']) : '';
                if (str_starts_with($path, '/')) {
                    $backHref = $path . $q . $f;
                }
            }
        }
    }
    $_SESSION['current_realm'] = 'hub';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($name, ENT_QUOTES); ?></title>
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
        <style>
            .mh-doc-shell { max-width: 1100px; margin: 0 auto; padding: 24px; box-sizing: border-box; }
            .mh-doc-head { display:flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
            .mh-doc-title { font-family: 'Orbitron', sans-serif; letter-spacing: 1px; color: var(--theme-primary, #00d4ff); margin: 0; }
            .mh-doc-btn { display:inline-flex; align-items:center; justify-content:center; padding: 10px 14px; border-radius: 999px; border: 1px solid rgba(0,212,255,.35); background: rgba(0,212,255,.14); color: #e8eefc; text-decoration:none; font-weight: 900; }
            .mh-doc-frame { width: 100%; height: min(78vh, 900px); border: 1px solid rgba(255,255,255,.14); border-radius: 14px; overflow:hidden; background: rgba(12,18,34,.92); }
            .mh-doc-pre { width: 100%; border: 1px solid rgba(255,255,255,.14); border-radius: 14px; padding: 16px; background: rgba(12,18,34,.92); color: rgba(255,255,255,.85); overflow:auto; white-space: pre-wrap; }
            .mh-doc-note { margin-top: 12px; color: rgba(255,255,255,.70); }
        </style>
    </head>
    <body class="hub-page">
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
        <main class="main-content">
            <div class="mh-doc-shell">
                <div class="mh-doc-head">
                    <h1 class="mh-doc-title"><?php echo htmlspecialchars($name, ENT_QUOTES); ?></h1>
                    <div style="display:flex; gap: 10px; flex-wrap: wrap;">
                        <a class="mh-doc-btn" href="<?php echo htmlspecialchars($dlUrl, ENT_QUOTES); ?>">Download</a>
                        <a class="mh-doc-btn" href="<?php echo htmlspecialchars($backHref, ENT_QUOTES); ?>">Back</a>
                    </div>
                </div>
                <div style="margin-top: 16px;">
                    <?php if ($ext === 'pdf' || $ext === 'docx'): ?>
                        <iframe class="mh-doc-frame" src="<?php echo htmlspecialchars($fileUrl, ENT_QUOTES); ?>"></iframe>
                        <?php if ($ext === 'docx'): ?>
                            <div class="mh-doc-note">If your browser can’t preview DOCX inline, use Download.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php
                            $raw = @file_get_contents((string)$att['path']);
                            if (!is_string($raw)) $raw = '';
                            if (strlen($raw) > 2_000_000) {
                                $raw = substr($raw, 0, 2_000_000) . "\n\n[Truncated]";
                            }
                        ?>
                        <pre class="mh-doc-pre"><?php echo htmlspecialchars($raw, ENT_QUOTES); ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
    </body>
    </html>
    <?php
    exit;
}

if ($ajax === 'dismiss') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? 'GET', 'POST') !== 0) {
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
    if ($id === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_id'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        $pdoTok = function_exists('mh_tokenomics_get_tokenomics_pdo') ? mh_tokenomics_get_tokenomics_pdo() : null;
        if (!$pdoTok instanceof PDO) throw new RuntimeException('tokenomics_unavailable');
        $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ok = mh_notices_set_dismissed($pdoTok, $username, $id);
        if ($ok) {
            mh_notices_add_event($pdoTok, $username, $id, 'dismiss');
        }
        echo json_encode(['ok' => $ok], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'dismiss_failed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($ajax === 'event') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? 'GET', 'POST') !== 0) {
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $id = isset($_POST['id']) ? trim((string)$_POST['id']) : '';
    $type = isset($_POST['type']) ? strtolower(trim((string)$_POST['type'])) : '';
    if ($id === '' || $type === '') {
        echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!in_array($type, ['view', 'read'], true)) {
        echo json_encode(['ok' => false, 'error' => 'invalid_type'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        $pdoTok = function_exists('mh_tokenomics_get_tokenomics_pdo') ? mh_tokenomics_get_tokenomics_pdo() : null;
        if (!$pdoTok instanceof PDO) throw new RuntimeException('tokenomics_unavailable');
        $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ok = mh_notices_add_event($pdoTok, $username, $id, $type);
        echo json_encode(['ok' => $ok], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable) {
        echo json_encode(['ok' => false, 'error' => 'event_failed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($ajax === '' || $ajax === 'page') {
    $feed = mh_notices_collect_feed($username, false, false);
    $dismissed = isset($feed['dismissed']) && is_array($feed['dismissed']) ? $feed['dismissed'] : [];
    $all = isset($feed['notices']) && is_array($feed['notices']) ? $feed['notices'] : [];
    $events = [];
    try {
        $pdoTok = function_exists('mh_tokenomics_get_tokenomics_pdo') ? mh_tokenomics_get_tokenomics_pdo() : null;
        if ($pdoTok instanceof PDO) {
            $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $events = mh_notices_get_events_map($pdoTok, $username);
        }
    } catch (Throwable) { $events = []; }

    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $per = isset($_GET['per']) ? trim((string)$_GET['per']) : '30';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perVal = $per === 'all' ? 0 : (int)$per;
    if (!in_array($perVal, [0, 10, 30, 50, 100], true)) $perVal = 30;

    if ($q !== '') {
        $qq = mb_strtolower($q);
        $all = array_values(array_filter($all, function ($n) use ($qq) {
            if (!is_array($n)) return false;
            $id = mb_strtolower(trim((string)($n['id'] ?? '')));
            $t = mb_strtolower(trim((string)($n['title'] ?? '')));
            $b = mb_strtolower(trim((string)($n['body'] ?? '')));
            return ($id !== '' && strpos($id, $qq) !== false) || ($t !== '' && strpos($t, $qq) !== false) || ($b !== '' && strpos($b, $qq) !== false);
        }));
    }

    $total = count($all);
    $items = $all;
    $pages = 1;
    if ($perVal > 0) {
        $pages = max(1, (int)ceil($total / $perVal));
        if ($page > $pages) $page = $pages;
        $off = ($page - 1) * $perVal;
        $items = array_slice($all, $off, $perVal);
    }

    $baseQs = function (array $extra = []) use ($q, $per, $page): string {
        $p = ['q' => $q, 'per' => $per, 'page' => $page];
        foreach ($extra as $k => $v) $p[$k] = $v;
        return '?' . http_build_query($p);
    };

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Notices</title>
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
        <style>
            body.hub-page main.main-content { padding: 26px 0; }
            .mh-n-page { max-width: 1200px; margin: 0 auto; padding: 0 20px 40px; box-sizing: border-box; }
            .mh-n-head { display:flex; align-items:flex-end; justify-content:space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
            .mh-n-head h1 { margin:0; font-family:'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 1px; }
            .mh-n-tools { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; }
            .mh-n-tools input, .mh-n-tools select { background: rgba(0,0,0,0.3); border: 1px solid rgba(0,212,255,0.25); color: rgba(255,255,255,0.92); padding: 10px 12px; border-radius: 10px; }
            .mh-n-tools button, .mh-n-tools a { background: rgba(0,212,255,0.12); border: 1px solid rgba(0,212,255,0.35); color: rgba(255,255,255,0.92); padding: 10px 12px; border-radius: 10px; cursor:pointer; text-decoration:none; font-weight: 900; }
            .mh-n-card { background: rgba(20,20,25,0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; padding: 16px; overflow:hidden; }
            .mh-n-table { width:100%; border-collapse: collapse; }
            .mh-n-table th, .mh-n-table td { padding: 12px 10px; border-bottom: 1px solid rgba(255,255,255,0.08); vertical-align: top; }
            .mh-n-table th { text-align:left; font-size: 12px; letter-spacing: 1px; color: rgba(255,255,255,0.72); font-weight: 900; }
            .mh-n-id { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; color: rgba(255,255,255,0.70); }
            .mh-n-title { font-weight: 900; color: rgba(255,255,255,0.96); margin-bottom: 4px; }
            .mh-n-body { white-space: pre-wrap; color: rgba(255,255,255,0.78); line-height: 1.55; }
            .mh-n-badge { display:inline-flex; padding: 4px 10px; border-radius: 999px; font-weight: 900; letter-spacing: 1px; font-size: 12px; }
            .mh-n-info { background: rgba(0,212,255,0.12); border: 1px solid rgba(0,212,255,0.30); color: rgba(255,255,255,0.92); }
            .mh-n-success { background: rgba(16,185,129,0.14); border: 1px solid rgba(16,185,129,0.35); color: #10b981; }
            .mh-n-warning { background: rgba(245,158,11,0.14); border: 1px solid rgba(245,158,11,0.35); color: #f59e0b; }
            .mh-n-error { background: rgba(239,68,68,0.14); border: 1px solid rgba(239,68,68,0.35); color: #ef4444; }
            .mh-n-muted { color: rgba(255,255,255,0.65); font-size: 12px; }
            .mh-n-actions { display:flex; gap: 8px; flex-wrap: wrap; align-items:center; }
            .mh-n-actions a, .mh-n-actions button { width:auto; padding: 8px 10px; border-radius: 10px; }
            .mh-n-actions button.mh-n-dismissed { opacity: 0.45; cursor: default; }
            .mh-n-pager { margin-top: 12px; display:flex; gap: 10px; align-items:center; flex-wrap: wrap; }
            .mh-n-pager a { padding: 8px 10px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.18); color: rgba(255,255,255,0.9); text-decoration:none; background: rgba(0,0,0,0.22); font-weight: 900; }
        </style>
    </head>
    <body class="hub-page">
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
        <main class="main-content">
            <div class="mh-n-page">
                <div class="mh-n-head">
                    <div>
                        <h1>NOTICES</h1>
                        <div class="mh-n-muted"><?php echo number_format((int)$total); ?> total</div>
                    </div>
                    <form class="mh-n-tools" method="GET" action="/hub/notices.php">
                        <input type="text" name="q" value="<?php echo htmlspecialchars((string)$q, ENT_QUOTES); ?>" placeholder="Search title/body/id">
                        <select name="per">
                            <?php foreach (['10','30','50','100','all'] as $opt): ?>
                                <option value="<?php echo $opt; ?>"<?php if ($per === $opt) echo ' selected'; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Search</button>
                        <a href="/hub/notices.php">Reset</a>
                    </form>
                </div>

                <div class="mh-n-card">
                    <table class="mh-n-table">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Date/Time</th>
                                <th>Notice</th>
                                <th style="width: 160px;">Type</th>
                                <th style="width: 220px;">ID</th>
                                <th style="width: 220px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $n): ?>
                            <?php
                                $id = isset($n['id']) ? trim((string)$n['id']) : '';
                                $ts = isset($n['ts']) ? (int)$n['ts'] : 0;
                                $title = isset($n['title']) ? trim((string)$n['title']) : '';
                                $body = isset($n['body']) ? trim((string)$n['body']) : '';
                                $type = isset($n['type']) ? trim((string)$n['type']) : 'info';
                                $url = isset($n['url']) ? trim((string)$n['url']) : '';
                                $pinned = !empty($n['pinned']);
                                $dismissedAt = $id !== '' && isset($dismissed[$id]) ? (string)$dismissed[$id] : '';
                                $ev = $id !== '' && isset($events[$id]) && is_array($events[$id]) ? $events[$id] : [];
                                $firstViewed = (is_array($ev) && isset($ev['view']) && is_array($ev['view']) && isset($ev['view'][0])) ? (string)$ev['view'][0] : '';
                                $firstRead = (is_array($ev) && isset($ev['read']) && is_array($ev['read']) && isset($ev['read'][0])) ? (string)$ev['read'][0] : '';
                                $dt = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
                                $badgeCls = $type === 'success' ? 'mh-n-success' : ($type === 'warning' ? 'mh-n-warning' : ($type === 'error' ? 'mh-n-error' : 'mh-n-info'));
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:900;"><?php echo htmlspecialchars($dt, ENT_QUOTES); ?></div>
                                    <?php if ($pinned): ?><div class="mh-n-muted">PINNED</div><?php endif; ?>
                                    <?php if ($dismissedAt !== ''): ?><div class="mh-n-muted">DISMISSED</div><?php endif; ?>
                                </td>
                                <td>
                                    <div class="mh-n-title"><?php echo htmlspecialchars($title !== '' ? $title : 'Notice', ENT_QUOTES); ?></div>
                                    <?php if ($body !== ''): ?><div class="mh-n-body"><?php echo htmlspecialchars($body, ENT_QUOTES); ?></div><?php endif; ?>
                                    <div style="margin-top:10px; padding:10px; border-radius:12px; border:1px solid rgba(255,255,255,0.10); background: rgba(0,0,0,0.18);">
                                        <div class="mh-n-muted" style="font-weight:900; letter-spacing:1px; margin-bottom:6px;">STATE</div>
                                        <div class="mh-n-muted">Viewed: <?php echo htmlspecialchars($firstViewed !== '' ? $firstViewed : '-', ENT_QUOTES); ?></div>
                                        <div class="mh-n-muted">Read: <?php echo htmlspecialchars($firstRead !== '' ? $firstRead : '-', ENT_QUOTES); ?></div>
                                        <div class="mh-n-muted">Dismissed: <?php echo htmlspecialchars($dismissedAt !== '' ? $dismissedAt : '-', ENT_QUOTES); ?></div>
                                    </div>
                                </td>
                                <td><span class="mh-n-badge <?php echo $badgeCls; ?>"><?php echo htmlspecialchars(strtoupper($type), ENT_QUOTES); ?></span></td>
                                <td class="mh-n-id"><?php echo htmlspecialchars($id, ENT_QUOTES); ?></td>
                                <td>
                                    <div class="mh-n-actions">
                                        <?php if ($url !== ''): ?><a href="<?php echo htmlspecialchars($url, ENT_QUOTES); ?>">Open</a><?php endif; ?>
                                        <?php if ($dismissedAt === ''): ?>
                                            <button type="button" onclick="mhDismissNotice(<?php echo json_encode($id); ?>)">Dismiss</button>
                                        <?php else: ?>
                                            <button type="button" class="mh-n-dismissed" disabled>Dismissed</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($perVal > 0 && $pages > 1): ?>
                        <div class="mh-n-pager">
                            <div class="mh-n-muted">Page <?php echo (int)$page; ?> / <?php echo (int)$pages; ?></div>
                            <?php if ($page > 1): ?><a href="/hub/notices.php<?php echo htmlspecialchars($baseQs(['page' => $page - 1]), ENT_QUOTES); ?>">Prev</a><?php endif; ?>
                            <?php if ($page < $pages): ?><a href="/hub/notices.php<?php echo htmlspecialchars($baseQs(['page' => $page + 1]), ENT_QUOTES); ?>">Next</a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
        <script>
            (function () {
                try {
                    const key = "mh_notice_events_logged_v1";
                    const raw = localStorage.getItem(key);
                    const j = raw ? JSON.parse(raw) : {};
                    if (!j || typeof j !== "object") return;
                } catch (e) {}
            })();
            async function mhDismissNotice(id) {
                try {
                    const resp = await fetch('/hub/notices.php?ajax=dismiss', {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: 'id=' + encodeURIComponent(String(id || ''))
                    });
                    const j = await resp.json().catch(() => null);
                    if (j && j.ok) window.location.reload();
                } catch (e) {}
            }

            function mhLogOnce(type, id) {
                try {
                    const k = "mh_notice_events_logged_v1";
                    const raw = localStorage.getItem(k);
                    const map = raw ? JSON.parse(raw) : {};
                    if (!map || typeof map !== "object") return;
                    const kk = String(type || "") + "|" + String(id || "");
                    if (!kk || map[kk]) return;
                    map[kk] = 1;
                    localStorage.setItem(k, JSON.stringify(map));
                } catch (e) { return; }
                try {
                    fetch("/hub/notices.php?ajax=event", {
                        method: "POST",
                        credentials: "include",
                        headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
                        body: "type=" + encodeURIComponent(String(type || "")) + "&id=" + encodeURIComponent(String(id || "")),
                        keepalive: true
                    }).catch(function () {});
                } catch (e) {}
            }

            document.addEventListener("DOMContentLoaded", function () {
                try {
                    const ids = Array.from(document.querySelectorAll("td.mh-n-id")).map(function (el) { return (el.textContent || "").trim(); }).filter(Boolean);
                    for (let i = 0; i < ids.length; i++) mhLogOnce("view", ids[i]);
                } catch (e) {}
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

if ($ajax === 'feed') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $feed = mh_notices_collect_feed($username, true, true);
    $panelCfg = isset($feed['panel']) && is_array($feed['panel']) ? $feed['panel'] : [];
    $maxTs = (int)($feed['max_ts'] ?? 0);
    $notices = isset($feed['notices']) && is_array($feed['notices']) ? $feed['notices'] : [];

    echo json_encode([
        'ok' => true,
        'user' => ['username' => $username],
        'panel' => $panelCfg,
        'max_ts' => $maxTs,
        'notices' => $notices,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => false, 'error' => 'bad_request'], JSON_UNESCAPED_SLASHES);
exit;
