<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/.cue/cue.php';
require_once dirname(__DIR__) . '/auth/auth_functions.php';

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($username === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode('/hub/notice-archive.php'));
    exit;
}

function mh_notice_archive_data_path(): string
{
    $p = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $p = $p !== '' ? rtrim($p, '/') : '/data';
    return $p;
}

function mh_notice_archive_cfg_path(): string
{
    return mh_notice_archive_data_path() . '/widgets/notices/widgets-config.json';
}

function mh_notice_archive_custom_cfg_path(): string
{
    return mh_notice_archive_data_path() . '/widgets/notices/custom-notices.json';
}

function mh_notice_archive_read_config(): array
{
    $p = mh_notice_archive_cfg_path();
    if (!is_file($p)) return [];
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function mh_notice_archive_read_custom_config(): array
{
    $p = mh_notice_archive_custom_cfg_path();
    $base = mh_notice_archive_data_path();
    try {
        if (function_exists('cue_autoload')) {
            $paths = cue_autoload('paths');
            if (is_object($paths) && method_exists($paths, 'validateSecurePath')) {
                $safe = $paths->validateSecurePath($p, $base);
                if (is_string($safe) && $safe !== '') $p = $safe;
            }
        }
    } catch (Throwable) {}
    if (!is_file($p)) return [];
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function mh_notice_archive_ts(string $dt): int
{
    $dt = trim($dt);
    if ($dt === '') return 0;
    $t = strtotime($dt);
    return $t ? (int)$t : 0;
}

$cfgGear = mh_notice_archive_read_custom_config();
$cfgWidgets = mh_notice_archive_read_config();
$listGear = isset($cfgGear['custom_messages']) && is_array($cfgGear['custom_messages']) ? $cfgGear['custom_messages'] : [];
$listWidgets = isset($cfgWidgets['custom_messages']) && is_array($cfgWidgets['custom_messages']) ? $cfgWidgets['custom_messages'] : [];
$all = [];
$seen = [];
foreach (array_merge($listGear, $listWidgets) as $m) {
    if (!is_array($m)) continue;
    $id = isset($m['id']) ? trim((string)$m['id']) : '';
    if ($id !== '' && isset($seen[$id])) continue;
    if ($id !== '') $seen[$id] = true;
    $all[] = $m;
}
$now = time();
$archived = [];
foreach ($all as $m) {
    if (!is_array($m)) continue;
    $title = isset($m['title']) ? trim((string)$m['title']) : '';
    $body = isset($m['body']) ? trim((string)$m['body']) : '';
    $url = isset($m['url']) ? trim((string)$m['url']) : '';
    if ($title === '' && $body === '' && $url === '') continue;
    $status = isset($m['status']) ? strtolower(trim((string)$m['status'])) : 'active';
    $archAt = isset($m['archived_at']) ? trim((string)$m['archived_at']) : '';
    $expAt = isset($m['expires_at']) ? trim((string)$m['expires_at']) : '';
    $isArchived = ($status === 'archived' || $archAt !== '');
    $isExpired = ($status === 'expired');
    if (!$isExpired && $expAt !== '') {
        $expTs = mh_notice_archive_ts($expAt);
        if ($expTs > 0 && $expTs <= $now) $isExpired = true;
    }
    if (!$isArchived && !$isExpired) continue;
    $createdAt = isset($m['created_at']) ? trim((string)$m['created_at']) : '';
    $ts = mh_notice_archive_ts($isArchived && $archAt !== '' ? $archAt : ($isExpired ? $expAt : $createdAt));
    $archived[] = [
        'id' => isset($m['id']) ? trim((string)$m['id']) : '',
        'title' => $title !== '' ? $title : 'Notice',
        'body' => $body,
        'url' => $url,
        'type' => isset($m['type']) ? trim((string)$m['type']) : 'info',
        'pinned' => !empty($m['pinned']),
        'created_at' => $createdAt,
        'archived_at' => $archAt,
        'expires_at' => $expAt,
        'status' => $isArchived ? 'archived' : 'expired',
        'ts' => $ts,
    ];
}

usort($archived, function ($a, $b) {
    $at = (int)($a['ts'] ?? 0);
    $bt = (int)($b['ts'] ?? 0);
    return $bt <=> $at;
});

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$per = isset($_GET['per']) ? trim((string)$_GET['per']) : '30';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perVal = $per === 'all' ? 0 : (int)$per;
if (!in_array($perVal, [0, 10, 30, 50, 100], true)) $perVal = 30;

if ($q !== '') {
    $qq = mb_strtolower($q);
    $archived = array_values(array_filter($archived, function ($n) use ($qq) {
        $id = mb_strtolower(trim((string)($n['id'] ?? '')));
        $t = mb_strtolower(trim((string)($n['title'] ?? '')));
        $b = mb_strtolower(trim((string)($n['body'] ?? '')));
        return ($id !== '' && strpos($id, $qq) !== false) || ($t !== '' && strpos($t, $qq) !== false) || ($b !== '' && strpos($b, $qq) !== false);
    }));
}

$total = count($archived);
$items = $archived;
$pages = 1;
if ($perVal > 0) {
    $pages = max(1, (int)ceil($total / $perVal));
    if ($page > $pages) $page = $pages;
    $off = ($page - 1) * $perVal;
    $items = array_slice($archived, $off, $perVal);
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
    <title>Notice Archive</title>
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
        .mh-n-muted { color: rgba(255,255,255,0.65); font-size: 12px; }
        .mh-n-info { background: rgba(0,212,255,0.12); border: 1px solid rgba(0,212,255,0.30); color: rgba(255,255,255,0.92); }
        .mh-n-warning { background: rgba(245,158,11,0.14); border: 1px solid rgba(245,158,11,0.35); color: #f59e0b; }
        .mh-n-error { background: rgba(239,68,68,0.14); border: 1px solid rgba(239,68,68,0.35); color: #ef4444; }
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
                    <h1>NOTICE ARCHIVE</h1>
                    <div class="mh-n-muted"><?php echo number_format((int)$total); ?> total</div>
                </div>
                <form class="mh-n-tools" method="GET" action="/hub/notice-archive.php">
                    <input type="text" name="q" value="<?php echo htmlspecialchars((string)$q, ENT_QUOTES); ?>" placeholder="Search title/body/id">
                    <select name="per">
                        <?php foreach (['10','30','50','100','all'] as $opt): ?>
                            <option value="<?php echo $opt; ?>"<?php if ($per === $opt) echo ' selected'; ?>><?php echo $opt; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Search</button>
                    <a href="/hub/notice-archive.php">Reset</a>
                    <a href="/templates/widgets/notices/widgets-config.php">Widget Settings</a>
                    <a href="/hub/notices.php">All Notices</a>
                </form>
            </div>

            <div class="mh-n-card">
                <table class="mh-n-table">
                    <thead>
                        <tr>
                            <th style="width: 160px;">Status</th>
                            <th style="width: 160px;">Created</th>
                            <th>Notice</th>
                            <th style="width: 220px;">ID</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $n): ?>
                        <?php
                            $id = isset($n['id']) ? trim((string)$n['id']) : '';
                            $title = isset($n['title']) ? trim((string)$n['title']) : '';
                            $body = isset($n['body']) ? trim((string)$n['body']) : '';
                            $status = isset($n['status']) ? strtoupper(trim((string)$n['status'])) : 'ARCHIVED';
                            $createdAt = isset($n['created_at']) ? trim((string)$n['created_at']) : '';
                            $badgeCls = $status === 'EXPIRED' ? 'mh-n-warning' : 'mh-n-info';
                        ?>
                        <tr>
                            <td><span class="mh-n-badge <?php echo $badgeCls; ?>"><?php echo htmlspecialchars($status, ENT_QUOTES); ?></span></td>
                            <td><div style="font-weight:900;"><?php echo htmlspecialchars($createdAt !== '' ? $createdAt : '-', ENT_QUOTES); ?></div></td>
                            <td>
                                <div class="mh-n-title"><?php echo htmlspecialchars($title !== '' ? $title : 'Notice', ENT_QUOTES); ?></div>
                                <?php if ($body !== ''): ?><div class="mh-n-body"><?php echo htmlspecialchars($body, ENT_QUOTES); ?></div><?php endif; ?>
                            </td>
                            <td class="mh-n-id"><?php echo htmlspecialchars($id !== '' ? $id : '-', ENT_QUOTES); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($perVal > 0 && $pages > 1): ?>
                    <div class="mh-n-pager">
                        <div class="mh-n-muted">Page <?php echo (int)$page; ?> / <?php echo (int)$pages; ?></div>
                        <?php if ($page > 1): ?><a href="/hub/notice-archive.php<?php echo htmlspecialchars($baseQs(['page' => $page - 1]), ENT_QUOTES); ?>">Prev</a><?php endif; ?>
                        <?php if ($page < $pages): ?><a href="/hub/notice-archive.php<?php echo htmlspecialchars($baseQs(['page' => $page + 1]), ENT_QUOTES); ?>">Next</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
</body>
</html>
