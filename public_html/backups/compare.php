<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_DISABLE_AUTO_LAYOUT', true);

require_once dirname(__DIR__) . '/.cue/cue.php';
require_once dirname(__DIR__) . '/auth/kripz_gate.php';
if (is_file(dirname(__DIR__) . '/auth/tenant_provisioning.php')) {
    require_once dirname(__DIR__) . '/auth/tenant_provisioning.php';
}
$cmpCueAutoload = function_exists('cue_autoload') ? 'cue_autoload' : null;
if (is_string($cmpCueAutoload)) {
    call_user_func($cmpCueAutoload, 'database');
    call_user_func($cmpCueAutoload, 'security');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
$r = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
if ($u === '' || stripos($r, 'kripzmaster') === false) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

function cmp_backup_root(): string {
    $root = is_dir('/backup') ? '/backup/backups' : '/backups';
    return rtrim($root, '/') . '/mysql-dumps';
}

function cmp_load_db_cfgs(): array {
    $path = '/data/config/db_configs.json';
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $id => $c) {
        if (!is_string($id) || !is_array($c)) continue;
        $port = is_string($c['port'] ?? null) ? (string)$c['port'] : '';
        $type = is_string($c['type'] ?? null) ? (string)$c['type'] : '';
        if ($port !== '3307' || $type !== 'mariadb') continue;
        $out[$id] = [
            'id' => $id,
            'name' => is_string($c['name'] ?? null) ? (string)$c['name'] : $id,
            'context' => is_string($c['context'] ?? null) ? (string)$c['context'] : '',
            'active' => !empty($c['is_active']),
        ];
    }
    return $out;
}

function cmp_decrypt_cfg(string $configId): ?array {
    $getFn = function_exists('database_getConfiguration') ? 'database_getConfiguration' : null;
    $decFn = function_exists('database_decryptConfiguration') ? 'database_decryptConfiguration' : null;
    if (!is_string($getFn) || !is_string($decFn)) return null;
    $cfg = call_user_func($getFn, $configId);
    if (!is_array($cfg)) return null;
    $dec = call_user_func($decFn, $cfg);
    return is_array($dec) ? $dec : null;
}

function cmp_provisioner_cfg_id(): ?string {
    if (function_exists('mh_find_block_provisioner_config_id')) {
        $id = mh_find_block_provisioner_config_id();
        if (is_string($id) && $id !== '') return $id;
    }
    return null;
}

function cmp_pdo_for_provisioner(): PDO {
    $id = cmp_provisioner_cfg_id();
    if (!is_string($id) || $id === '') throw new RuntimeException('missing_db_provisioner_config');
    $getFn = function_exists('database_getConnectionById') ? 'database_getConnectionById' : null;
    if (!is_string($getFn)) throw new RuntimeException('database_module_unavailable');
    $pdo = call_user_func($getFn, $id);
    if (!$pdo instanceof PDO) throw new RuntimeException('provisioner_connection_failed');
    return $pdo;
}

function cmp_tmp_defaults_file(array $cfg): string {
    $host = (string)($cfg['host'] ?? '127.0.0.1');
    $port = (string)($cfg['port'] ?? '3307');
    $user = (string)($cfg['username'] ?? '');
    $pass = (string)($cfg['password'] ?? '');
    $tmp = '/tmp/mh_mysql_cmp_' . bin2hex(random_bytes(8)) . '.cnf';
    $body = "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\nport={$port}\n";
    @file_put_contents($tmp, $body, LOCK_EX);
    @chmod($tmp, 0600);
    return $tmp;
}

function cmp_mysql_import_gz(string $defaultsFile, string $dbName, string $gzPath): array {
    $cmd = ['mysql', '--defaults-extra-file=' . $defaultsFile, '--database=' . $dbName];
    $descriptor = [
        0 => ['pipe', 'w'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) return ['ok' => false, 'error' => 'proc_open_failed'];
    $in = $pipes[0];
    $out = $pipes[1];
    $err = $pipes[2];
    stream_set_blocking($in, true);
    stream_set_blocking($out, true);
    stream_set_blocking($err, true);
    $gz = @gzopen($gzPath, 'rb');
    if (!$gz) {
        @fclose($in); @fclose($out); @fclose($err); @proc_close($proc);
        return ['ok' => false, 'error' => 'gzopen_failed'];
    }
    $written = 0;
    while (!gzeof($gz)) {
        $line = gzgets($gz);
        if ($line === false) break;
        if ($line === '') continue;
        $trim = ltrim($line);
        $skip = false;
        if (stripos($trim, 'USE ') === 0 || stripos($trim, 'USE`') === 0) {
            $skip = true;
        } elseif (stripos($trim, 'CREATE DATABASE') === 0) {
            $skip = true;
        } elseif (stripos($trim, 'DROP DATABASE') === 0) {
            $skip = true;
        } elseif (substr($trim, 0, 3) === '/*!') {
            if (stripos($trim, 'CREATE DATABASE') !== false) $skip = true;
            if (stripos($trim, 'DROP DATABASE') !== false) $skip = true;
            if (preg_match('/\\bUSE\\b/i', $trim)) $skip = true;
        }
        if ($skip) continue;
        $n = fwrite($in, $line);
        if ($n === false) break;
        $written += (int)$n;
    }
    gzclose($gz);
    @fclose($in);
    $stdout = stream_get_contents($out);
    $stderr = stream_get_contents($err);
    @fclose($out);
    @fclose($err);
    $code = @proc_close($proc);
    $code = is_int($code) ? $code : 2;
    if ($code !== 0) {
        return ['ok' => false, 'error' => 'mysql_exit_' . $code, 'stderr' => is_string($stderr) ? trim($stderr) : ''];
    }
    return ['ok' => true, 'bytes_written' => $written, 'stdout' => is_string($stdout) ? trim($stdout) : ''];
}

function cmp_list_dump_files(string $dbName): array {
    $root = cmp_backup_root();
    $safeDb = trim($dbName);
    $safeDb = preg_replace('/[^a-zA-Z0-9_\\-\\.]+/', '_', $safeDb);
    $safeDb = is_string($safeDb) ? trim($safeDb, '._-') : 'db';
    if ($safeDb === '') $safeDb = 'db';
    if (strlen($safeDb) > 80) $safeDb = substr($safeDb, 0, 80);
    $base = rtrim($root, '/') . '/' . $safeDb;
    $files = [];
    foreach (['hourly', 'daily', 'weekly', 'monthly'] as $freq) {
        $dir = $base . '/' . $freq;
        if (!is_dir($dir)) continue;
        $tmp = glob($dir . '/*.sql.gz') ?: [];
        rsort($tmp, SORT_STRING);
        foreach (array_slice($tmp, 0, 60) as $f) {
            $files[] = ['path' => $f, 'freq' => $freq, 'name' => basename($f)];
        }
    }
    usort($files, fn($a, $b) => strcmp((string)$b['name'], (string)$a['name']));
    return $files;
}

function cmp_ident_ok(string $s): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $s);
}

function cmp_fetch_tables(PDO $pdo, string $dbName): array {
    $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name');
    $stmt->execute([$dbName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $t = isset($r['table_name']) ? (string)$r['table_name'] : '';
        if ($t !== '' && cmp_ident_ok($t)) $out[] = $t;
    }
    return $out;
}

function cmp_fetch_columns(PDO $pdo, string $dbName, string $table): array {
    $stmt = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position');
    $stmt->execute([$dbName, $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $c = isset($r['column_name']) ? (string)$r['column_name'] : '';
        if ($c !== '' && cmp_ident_ok($c)) $out[] = $c;
    }
    return $out;
}

function cmp_fetch_primary_key(PDO $pdo, string $dbName, string $table): ?string {
    $stmt = $pdo->prepare("SELECT k.column_name FROM information_schema.table_constraints t JOIN information_schema.key_column_usage k ON t.constraint_name = k.constraint_name AND t.table_schema = k.table_schema AND t.table_name = k.table_name WHERE t.constraint_type = 'PRIMARY KEY' AND t.table_schema = ? AND t.table_name = ? ORDER BY k.ordinal_position LIMIT 1");
    $stmt->execute([$dbName, $table]);
    $v = $stmt->fetchColumn();
    $v = is_string($v) ? trim($v) : '';
    if ($v !== '' && cmp_ident_ok($v)) return $v;
    return null;
}

$security = is_string($cmpCueAutoload) ? call_user_func($cmpCueAutoload, 'security') : null;
$csrf = $security ? $security->generateCSRFToken('backup_compare') : '';
$msg = '';
$err = '';

$cmp = isset($_SESSION['mh_backup_compare']) && is_array($_SESSION['mh_backup_compare']) ? $_SESSION['mh_backup_compare'] : [];
$activeTempDb = isset($cmp['temp_db']) ? (string)$cmp['temp_db'] : '';
$activeTargetId = isset($cmp['target_config_id']) ? (string)$cmp['target_config_id'] : '';
$activeDump = isset($cmp['dump_file']) ? (string)$cmp['dump_file'] : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tok = (string)($_POST['csrf_token'] ?? '');
    $ok = $security ? $security->validateCSRFToken($tok, 'backup_compare') : true;
    if (!$ok) {
        $err = 'Invalid session';
    } else {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        try {
            if ($action === 'drop_temp') {
                if ($activeTempDb !== '') {
                    $pdo = cmp_pdo_for_provisioner();
                    $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $activeTempDb) . '`');
                }
                unset($_SESSION['mh_backup_compare']);
                $activeTempDb = '';
                $activeTargetId = '';
                $activeDump = '';
                $msg = 'Cleared';
            } elseif ($action === 'prepare') {
                $targetId = isset($_POST['target_config_id']) ? trim((string)$_POST['target_config_id']) : '';
                $dumpFile = isset($_POST['dump_file']) ? trim((string)$_POST['dump_file']) : '';
                if ($targetId === '' || $dumpFile === '') throw new RuntimeException('target_and_dump_required');
                if (!is_file($dumpFile) || strpos($dumpFile, '..') !== false) throw new RuntimeException('invalid_dump_file');
                if (strpos($dumpFile, cmp_backup_root()) !== 0) throw new RuntimeException('dump_outside_root');
                $targetCfg = cmp_decrypt_cfg($targetId);
                if (!is_array($targetCfg)) throw new RuntimeException('target_config_not_found');
                $targetDb = isset($targetCfg['database']) ? trim((string)$targetCfg['database']) : '';
                if ($targetDb === '') throw new RuntimeException('target_db_missing');
                $tempDb = 'cmp_' . substr(hash('sha256', $targetDb . '|' . $dumpFile . '|' . microtime(true)), 0, 18);
                $pdo = cmp_pdo_for_provisioner();
                $pdo->exec('DROP DATABASE IF EXISTS `' . $tempDb . '`');
                $pdo->exec('CREATE DATABASE `' . $tempDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                $provId = cmp_provisioner_cfg_id();
                if (!is_string($provId) || $provId === '') throw new RuntimeException('missing_db_provisioner_config');
                $provCfg = cmp_decrypt_cfg($provId);
                if (!is_array($provCfg)) throw new RuntimeException('provisioner_config_missing');
                $defaultsFile = cmp_tmp_defaults_file($provCfg);
                $imp = cmp_mysql_import_gz($defaultsFile, $tempDb, $dumpFile);
                @unlink($defaultsFile);
                if (empty($imp['ok'])) {
                    $pdo->exec('DROP DATABASE IF EXISTS `' . $tempDb . '`');
                    throw new RuntimeException('import_failed:' . (string)($imp['error'] ?? 'unknown'));
                }
                $_SESSION['mh_backup_compare'] = [
                    'temp_db' => $tempDb,
                    'target_config_id' => $targetId,
                    'target_db' => $targetDb,
                    'dump_file' => $dumpFile,
                    'created_at' => time(),
                ];
                $activeTempDb = $tempDb;
                $activeTargetId = $targetId;
                $activeDump = $dumpFile;
                $msg = 'Imported backup into ' . $tempDb;
            } elseif ($action === 'apply_columns') {
                $table = isset($_POST['table']) ? trim((string)$_POST['table']) : '';
                $mode = isset($_POST['mode']) ? trim((string)$_POST['mode']) : 'update_only';
                $cols = isset($_POST['cols']) && is_array($_POST['cols']) ? $_POST['cols'] : [];
                $cols = array_values(array_filter(array_map(fn($c) => is_string($c) ? trim($c) : '', $cols), fn($c) => $c !== ''));
                if ($activeTempDb === '' || $activeTargetId === '') throw new RuntimeException('prepare_required');
                if ($table === '' || !cmp_ident_ok($table)) throw new RuntimeException('invalid_table');
                $targetCfg = cmp_decrypt_cfg($activeTargetId);
                if (!is_array($targetCfg)) throw new RuntimeException('target_config_not_found');
                $targetDb = isset($targetCfg['database']) ? trim((string)$targetCfg['database']) : '';
                if ($targetDb === '') throw new RuntimeException('target_db_missing');
                $pdo = cmp_pdo_for_provisioner();
                $pk = cmp_fetch_primary_key($pdo, $activeTempDb, $table);
                if ($pk === null) throw new RuntimeException('primary_key_required');
                $tempCols = cmp_fetch_columns($pdo, $activeTempDb, $table);
                $targetCols = cmp_fetch_columns($pdo, $targetDb, $table);
                $allowed = array_values(array_intersect($tempCols, $targetCols));
                $cols = array_values(array_intersect($cols, $allowed));
                $cols = array_values(array_filter($cols, fn($c) => $c !== $pk));
                if ($mode !== 'update_only' && $mode !== 'upsert' && $mode !== 'replace') $mode = 'update_only';

                if ($mode === 'replace') {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                    $pdo->exec('TRUNCATE TABLE `' . $targetDb . '`.`' . $table . '`');
                    $pdo->exec('INSERT INTO `' . $targetDb . '`.`' . $table . '` SELECT * FROM `' . $activeTempDb . '`.`' . $table . '`');
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                    $msg = 'Replaced table ' . $table;
                } else {
                    if ($cols === []) throw new RuntimeException('no_columns_selected');
                    $sets = [];
                    foreach ($cols as $c) {
                        $sets[] = 't.`' . $c . '` = b.`' . $c . '`';
                    }
                    $sql = 'UPDATE `' . $targetDb . '`.`' . $table . '` t JOIN `' . $activeTempDb . '`.`' . $table . '` b ON t.`' . $pk . '` = b.`' . $pk . '` SET ' . implode(', ', $sets);
                    $pdo->exec($sql);
                    if ($mode === 'upsert') {
                        $colList = $allowed;
                        $insertCols = implode('`,`', $colList);
                        $selectCols = implode(', ', array_map(fn($c) => 'b.`' . $c . '`', $colList));
                        $sql2 = 'INSERT INTO `' . $targetDb . '`.`' . $table . '` (`' . $insertCols . '`) SELECT ' . $selectCols . ' FROM `' . $activeTempDb . '`.`' . $table . '` b LEFT JOIN `' . $targetDb . '`.`' . $table . '` t ON t.`' . $pk . '` = b.`' . $pk . '` WHERE t.`' . $pk . '` IS NULL';
                        $pdo->exec($sql2);
                        $msg = 'Upserted selected fields for ' . $table;
                    } else {
                        $msg = 'Updated selected fields for ' . $table;
                    }
                }
            }
        } catch (Throwable $e) {
            $err = $e->getMessage();
        }
    }
}

$dbCfgs = cmp_load_db_cfgs();
$selectedTarget = $activeTargetId !== '' ? $activeTargetId : (isset($_GET['target']) ? trim((string)$_GET['target']) : '');
$selectedDump = $activeDump !== '' ? $activeDump : '';
$targetDbName = '';
$dumps = [];
if ($selectedTarget !== '') {
    try {
        $cfg = cmp_decrypt_cfg($selectedTarget);
        $targetDbName = is_array($cfg) ? (string)($cfg['database'] ?? '') : '';
        if ($targetDbName !== '') {
            $dumps = cmp_list_dump_files($targetDbName);
        }
    } catch (Throwable $e) {
        $dumps = [];
    }
}

$tablesTemp = [];
$tablesTarget = [];
$table = isset($_GET['table']) ? trim((string)$_GET['table']) : '';
$cols = [];
$pk = null;
if ($activeTempDb !== '' && $activeTargetId !== '') {
    try {
        $pdo = cmp_pdo_for_provisioner();
        $cfg = cmp_decrypt_cfg($activeTargetId);
        $tDb = is_array($cfg) ? (string)($cfg['database'] ?? '') : '';
        if ($tDb !== '') {
            $tablesTemp = cmp_fetch_tables($pdo, $activeTempDb);
            $tablesTarget = cmp_fetch_tables($pdo, $tDb);
            if ($table !== '' && in_array($table, $tablesTemp, true) && in_array($table, $tablesTarget, true)) {
                $cols = array_values(array_intersect(cmp_fetch_columns($pdo, $activeTempDb, $table), cmp_fetch_columns($pdo, $tDb, $table)));
                $pk = cmp_fetch_primary_key($pdo, $activeTempDb, $table);
            }
        }
    } catch (Throwable $e) {
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Backup Compare</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        :root { --primary:#00d4ff; --bg:#050816; --panel:rgba(255,255,255,0.04); --border:rgba(0,212,255,0.22); --text:#e6f6ff; --muted:rgba(230,246,255,0.7); --danger:#ff6b6b; --ok:#00ff7f; }
        html { color-scheme: dark; }
        html,body { background:var(--bg); color:var(--text); font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, sans-serif; margin:0; }
        main { max-width: 1200px; margin:0 auto; padding: 18px 16px 60px; }
        .card { background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:14px; margin:12px 0; }
        .row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        label { display:block; font-size:12px; color:var(--muted); margin-bottom:6px; }
        select,input { background:#2b2b2b !important; color:var(--primary) !important; border:1px solid rgba(0,212,255,0.28); border-radius:10px; padding:10px 12px; }
        select { -webkit-appearance: none; appearance: none; }
        option { background:#2b2b2b !important; color:var(--primary) !important; }
        .btn { border-radius:10px; padding:10px 12px; border:1px solid rgba(0,212,255,0.45); background:rgba(0,212,255,0.14); color:var(--text); cursor:pointer; font-weight:800; }
        .btn.danger { border-color: rgba(255,107,107,0.55); background: rgba(255,107,107,0.16); }
        .msg { border:1px solid rgba(0,255,127,0.35); background: rgba(0,255,127,0.10); padding:10px 12px; border-radius:12px; margin:10px 0; }
        .err { border:1px solid rgba(255,107,107,0.35); background: rgba(255,107,107,0.10); padding:10px 12px; border-radius:12px; margin:10px 0; }
        .mono { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; }
        table { width:100%; border-collapse:collapse; }
        th,td { text-align:left; padding:10px 10px; border-top:1px solid rgba(255,255,255,0.08); }
        th { font-size:12px; color:var(--muted); }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <h1 style="margin:0 0 8px 0;color:var(--primary)">Backup Compare</h1>
    <div style="opacity:.8;font-size:12px;max-width:900px">Imports a selected MySQL dump into a temporary schema and applies selected column restores into the live schema.</div>
    <?php if ($msg !== ''): ?><div class="msg"><?php echo h($msg); ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="err"><?php echo h($err); ?></div><?php endif; ?>

    <div class="card">
        <div style="font-weight:900;margin-bottom:8px">1) Select target DB + dump</div>
        <form method="post" class="row">
            <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
            <input type="hidden" name="action" value="prepare">
            <div>
                <label>Target DB (config ID)</label>
                <select name="target_config_id" onchange="location.href='?target='+encodeURIComponent(this.value)">
                    <option value="">Select…</option>
                    <?php foreach ($dbCfgs as $id => $c): ?>
                        <option value="<?php echo h($id); ?>" <?php echo $selectedTarget === $id ? 'selected' : ''; ?>>
                            <?php echo h(($c['name'] ?? $id) . ' (' . $id . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:420px;max-width:100%;flex:1">
                <label>Dump file</label>
                <select name="dump_file" <?php echo $selectedTarget !== '' ? '' : 'disabled'; ?>>
                    <option value="">Select…</option>
                    <?php foreach ($dumps as $d): ?>
                        <option value="<?php echo h($d['path']); ?>" <?php echo $selectedDump === $d['path'] ? 'selected' : ''; ?>>
                            <?php echo h($d['freq'] . ' / ' . $d['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="mono" style="opacity:.65;font-size:12px;margin-top:6px"><?php echo h($targetDbName !== '' ? ('Target schema: ' . $targetDbName) : ''); ?></div>
            </div>
            <button class="btn" type="submit" <?php echo $selectedTarget !== '' ? '' : 'disabled'; ?>>Import for Compare</button>
        </form>
        <?php if ($activeTempDb !== ''): ?>
            <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <div class="mono">Temp schema: <?php echo h($activeTempDb); ?></div>
                <form method="post" style="margin:0">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="drop_temp">
                    <button class="btn danger" type="submit">Drop Temp Schema</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div style="font-weight:900;margin-bottom:8px">2) Select table + fields to restore</div>
        <?php if ($activeTempDb === ''): ?>
            <div style="opacity:.8">Import a dump first.</div>
        <?php else: ?>
            <?php
                $cfg = cmp_decrypt_cfg($activeTargetId);
                $tDb = is_array($cfg) ? (string)($cfg['database'] ?? '') : '';
                $commonTables = array_values(array_intersect($tablesTemp, $tablesTarget));
                sort($commonTables, SORT_STRING);
            ?>
            <form method="get" class="row">
                <input type="hidden" name="target" value="<?php echo h($activeTargetId); ?>">
                <div>
                    <label>Table</label>
                    <select name="table" onchange="this.form.submit()">
                        <option value="">Select…</option>
                        <?php foreach ($commonTables as $t): ?>
                            <option value="<?php echo h($t); ?>" <?php echo $table === $t ? 'selected' : ''; ?>><?php echo h($t); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>

            <?php if ($table !== '' && $pk !== null): ?>
                <div class="mono" style="opacity:.75;margin-top:10px">Primary key: <?php echo h($pk); ?></div>
                <form method="post" style="margin-top:10px">
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">
                    <input type="hidden" name="action" value="apply_columns">
                    <input type="hidden" name="table" value="<?php echo h($table); ?>">
                    <div class="row">
                        <div>
                            <label>Mode</label>
                            <select name="mode">
                                <option value="update_only">Update only (recommended)</option>
                                <option value="upsert">Upsert (update + insert missing)</option>
                                <option value="replace">Replace table (TRUNCATE + insert all)</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:12px">
                        <div style="opacity:.85;font-size:12px;margin-bottom:8px">Select columns to restore</div>
                        <div class="row" style="gap:8px;align-items:center">
                            <?php foreach ($cols as $c): ?>
                                <?php if ($c === $pk) continue; ?>
                                <label style="display:flex;gap:8px;align-items:center;margin:0 10px 6px 0">
                                    <input type="checkbox" name="cols[]" value="<?php echo h($c); ?>">
                                    <span class="mono"><?php echo h($c); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn" type="submit" style="margin-top:10px">Apply Restore</button>
                </form>
            <?php elseif ($table !== ''): ?>
                <div class="err" style="margin-top:10px">Primary key required for safe compare/restore. Add a primary key to this table or use Replace mode on a table that has one.</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
</body>
</html>
