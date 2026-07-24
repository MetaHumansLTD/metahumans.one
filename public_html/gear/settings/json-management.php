<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST' && isset($_POST['action'])) {
    define('CUE_ANIMATIONS_INITIALIZED', true);
}
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) {
    define('CUE_DISABLE_AUTO_LAYOUT', true);
}
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
if (function_exists('error_configure')) { error_configure(['display_errors' => true]); }
require_once dirname(dirname(__DIR__)) . '/.cue/json.php';
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors','1');
error_reporting(E_ALL);

// Include widgets
try {
    if (function_exists('includeNoticesWidget')) {
        includeNoticesWidget();
    }
} catch (Exception $e) {
    error_log('Failed to include notices widget: ' . $e->getMessage());
}

function jm_call($method, $args = []) {
    $obj = cue_autoload('json');
    if (is_object($obj) && method_exists($obj, $method)) {
        return $obj->$method(...$args);
    }
    $func = 'json_' . $method;
    if (function_exists($func)) {
        return $func(...$args);
    }
    return ['success' => false, 'error_code' => 'module_error', 'message' => 'json method unavailable'];
}

if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    if ($action === 'scan') {
        $res = jm_call('scanAndRebuildRegistry', []);
        $msg = $res['success'] ? 'Registry rebuilt successfully' : ('Scan failed: ' . ($res['message'] ?? 'Unknown error'));
        $type = $res['success'] ? 'success' : 'error';
        $GLOBALS['JM_NOTICE_SCRIPT'] = 'document.addEventListener("DOMContentLoaded", function(){ if(window.popupNotice){ window.popupNotice.' . $type . '(' . json_encode($msg) . '); }});';
    }
    if ($action === 'report') {
        $report = jm_call('findDuplicateKeys', []);
        $count = is_array($report) ? count($report) : 0;
        $msg = $count === 0 ? 'No duplicate keys found' : ($count . ' duplicate keys detected');
        $type = $count === 0 ? 'success' : 'warning';
        $GLOBALS['JM_NOTICE_SCRIPT'] = 'document.addEventListener("DOMContentLoaded", function(){ if(window.popupNotice){ window.popupNotice.' . $type . '(' . json_encode($msg) . '); }});';
        $duplicates = $report;
    }
    if ($action === 'assert') {
        $file = $_POST['file'] ?? '';
        $res = jm_call('assertFileKeysUnique', [$file]);
        $msg = $res['success'] ? ($res['message'] ?? 'File has unique keys') : ('Assertion failed: ' . ($res['message'] ?? 'Unknown error'));
        $type = $res['success'] ? 'success' : 'error';
        $GLOBALS['JM_NOTICE_SCRIPT'] = 'document.addEventListener("DOMContentLoaded", function(){ if(window.popupNotice){ window.popupNotice.' . $type . '(' . json_encode($msg) . '); }});';
    }
    if ($action === 'merge') {
        $key1 = $_POST['key1'] ?? '';
        $key2 = $_POST['key2'] ?? '';
        $res = jm_call('mergeDuplicates', [$key1, $key2]);
        $msg = $res['success'] ? 'Duplicates merged successfully' : ('Merge failed: ' . ($res['message'] ?? 'Unknown error'));
        $type = $res['success'] ? 'success' : 'error';
        $GLOBALS['JM_NOTICE_SCRIPT'] = 'document.addEventListener("DOMContentLoaded", function(){ if(window.popupNotice){ window.popupNotice.' . $type . '(' . json_encode($msg) . '); }});';
    }
    if ($action === 'remove') {
        $key = $_POST['key'] ?? '';
        $res = jm_call('removeKey', [$key]);
        $msg = $res['success'] ? 'Key removed successfully' : ('Remove failed: ' . ($res['message'] ?? 'Unknown error'));
        $type = $res['success'] ? 'success' : 'error';
        $GLOBALS['JM_NOTICE_SCRIPT'] = 'document.addEventListener("DOMContentLoaded", function(){ if(window.popupNotice){ window.popupNotice.' . $type . '(' . json_encode($msg) . '); }});';
    }
    if ($action === 'setpaths') {
        $paths = array_map('trim', explode("\n", $_POST['paths'] ?? ''));
        $paths = array_filter($paths);
        $res = jm_call('setAuditPaths', [$paths]);
        $msg = $res['success'] ? 'Audit paths updated' : ('Update failed: ' . ($res['message'] ?? 'Unknown error'));
        $type = $res['success'] ? 'success' : 'error';
        $GLOBALS['JM_NOTICE_SCRIPT'] = 'document.addEventListener("DOMContentLoaded", function(){ if(window.popupNotice){ window.popupNotice.' . $type . '(' . json_encode($msg) . '); }});';
    }
    if ($action === 'browse') {
        $baseSel = $_POST['base'] ?? 'data';
        $sub = trim((string)($_POST['subpath'] ?? ''));
        $bases = [
            'data' => function_exists('getDataPath') ? getDataPath() : dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.data',
            'templates' => function_exists('getTemplatesPath') ? getTemplatesPath() : dirname(dirname(__DIR__)),
            'public' => function_exists('getPublicPath') ? getPublicPath() : dirname(dirname(__DIR__)),
            'backups' => function_exists('getBackupsPath') ? getBackupsPath() : dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.backups',
            'rules' => function_exists('getRulesPath') ? getRulesPath() : dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . '.rules'
        ];
        $baseDir = $bases[$baseSel] ?? $bases['data'];
        $realBase = realpath($baseDir);
        $target = $realBase;
        if ($sub !== '') {
            $candidate = $baseDir . DIRECTORY_SEPARATOR . ltrim($sub, '/\\');
            $realCandidate = realpath($candidate);
            if ($realCandidate && strpos($realCandidate, $realBase) === 0) {
                $target = $realCandidate;
            }
        }
        $items = [];
        if (is_dir($target)) {
            foreach (scandir($target) as $name) {
                if ($name === '.' || $name === '..') { continue; }
                $p = $target . DIRECTORY_SEPARATOR . $name;
                $isDir = is_dir($p);
                $items[] = [
                    'name' => $name,
                    'type' => $isDir ? 'dir' : 'file',
                    'size' => $isDir ? '' : (string)@filesize($p),
                    'mtime' => (string)@date('Y-m-d H:i', @filemtime($p))
                ];
            }
        }
        $GLOBALS['JM_BROWSE'] = ['base' => $baseSel, 'base_path' => $realBase, 'path' => $target, 'items' => $items];
    }
}

$registry = function_exists('json_loadRegistry') ? json_loadRegistry() : [];
$entryCount = is_array($registry) ? count($registry) : 0;
$duplicates = jm_call('findDuplicateKeys', []);
$dupCount = is_array($duplicates) ? count($duplicates) : 0;

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>JSON Management</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:0;background:#f6f7fb;color:#222}
.container{max-width:1100px;margin:30px auto;padding:20px;background:#fff;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,0.08)}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.stats{display:flex;gap:20px}
.stat{background:#f2f4f8;padding:12px 16px;border-radius:8px}
.actions{display:flex;gap:12px;margin:20px 0}
button{padding:10px 14px;border:none;border-radius:6px;background:#2b7cff;color:#fff;cursor:pointer}
button.secondary{background:#6c757d}
button:disabled{opacity:.6;cursor:not-allowed}
.grid{margin-top:20px;border:1px solid #e9ecef;border-radius:8px;overflow:hidden}
.row{display:grid;grid-template-columns:2fr 3fr 2fr 2fr 2fr;gap:0;border-top:1px solid #e9ecef}
.row>div{padding:10px}
.row.header{background:#f8f9fa;font-weight:600}
.form{margin-top:20px;display:flex;gap:10px}
input[type=text]{flex:1;padding:10px;border:1px solid #ced4da;border-radius:6px}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f8d7da;color:#721c24;font-size:12px}
.audit-targets{margin:10px 0 20px;padding:10px;border:1px dashed #ced4da;border-radius:6px;background:#fafafa}
.browse{margin-top:20px;padding:12px;border:1px solid #e9ecef;border-radius:8px}
.browse-grid{margin-top:12px;border:1px solid #e9ecef;border-radius:8px;overflow:hidden}
.browse-row{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:0;border-top:1px solid #e9ecef}
.browse-row>div{padding:10px}
.browse-row.header{background:#f8f9fa;font-weight:600}
</style>
</head>
<body>
<div class="container">
<div class="header">
<h2>JSON Management</h2>
<div class="stats">
<div class="stat">Registry Entries: <?php echo (int)$entryCount; ?></div>
<div class="stat">Detected Duplicates: <?php echo (int)$dupCount; ?></div>
</div>
</div>
<div class="audit-targets">
<?php $auditDirs = [function_exists('getDataPath') ? getDataPath() : '', function_exists('getTemplatesPath') ? getTemplatesPath() : '']; ?>
<strong>Audit Targets:</strong>
<div><?php echo htmlspecialchars($auditDirs[0]); ?></div>
<div><?php echo htmlspecialchars($auditDirs[1]); ?></div>
</div>
<div class="form" style="margin-bottom:20px">
<form method="post">
<input type="hidden" name="action" value="setpaths">
<label>Custom Audit Paths (one per line):</label><br>
<textarea name="paths" rows="4" style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px"><?php echo htmlspecialchars(implode("\n", jm_call('getAuditPaths', []))); ?></textarea><br>
<button type="submit">Update Audit Paths</button>
</form>
</div>
<div class="actions">
<form method="post" style="display:inline">
<input type="hidden" name="action" value="scan">
<button type="submit">Run Audit: Scan & Rebuild Registry</button>
</form>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="report">
<button type="submit" class="secondary">List Duplicate Keys</button>
</form>
</div>
<div class="form">
<form method="post" style="display:flex;gap:10px;width:100%">
<input type="hidden" name="action" value="assert">
<input type="text" name="file" placeholder="Relative file path e.g. widgets/notices/widgets-config.json">
<button type="submit">Assert File Keys Unique</button>
</form>
</div>
<div class="browse">
<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
<input type="hidden" name="action" value="browse">
<label>Base</label>
<select name="base">
<option value="data">.data</option>
<option value="templates">public_html/templates</option>
<option value="public">public_html</option>
<option value="backups">.backups</option>
<option value="rules">.rules</option>
</select>
<input type="text" name="subpath" placeholder="Subpath">
<button type="submit">Browse</button>
</form>
<?php $b = $GLOBALS['JM_BROWSE'] ?? null; if ($b) { ?>
<div style="margin-top:8px;color:#666">Base: <?php echo htmlspecialchars((string)$b['base_path']); ?> | Path: <?php echo htmlspecialchars((string)$b['path']); ?></div>
<div class="browse-grid">
<div class="browse-row header"><div>Name</div><div>Type</div><div>Updated</div><div>Actions</div></div>
<?php foreach ($b['items'] as $it) { $isFile = ($it['type'] === 'file'); ?>
<div class="browse-row"><div><?php echo htmlspecialchars($it['name']); ?></div><div><?php echo htmlspecialchars($it['type']); ?></div><div><?php echo htmlspecialchars($it['mtime']); ?></div><div>
<?php if (!$isFile) { ?>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="browse">
<input type="hidden" name="base" value="<?php echo htmlspecialchars((string)($_POST['base'] ?? 'data')); ?>">
<input type="hidden" name="subpath" value="<?php echo htmlspecialchars(trim((string)($_POST['subpath'] ?? '')) === '' ? $it['name'] : (trim((string)($_POST['subpath'])) . '/' . $it['name'])); ?>">
<button type="submit" class="secondary">Open</button>
</form>
<?php } else { ?>
<button type="button" onclick="(function(){var f=document.querySelector('input[name=\'file\']'); if(f){var baseSel='<?php echo htmlspecialchars((string)($_POST['base'] ?? 'data')); ?>'; var sp='<?php echo htmlspecialchars((string)($_POST['subpath'] ?? '')); ?>'; var rel=''; if(baseSel==='templates'){ rel = 'templates/' + (sp ? sp + '/' : '') + '<?php echo htmlspecialchars($it['name']); ?>'; } else if(baseSel==='public'){ rel = (sp ? sp + '/' : '') + '<?php echo htmlspecialchars($it['name']); ?>'; } else if(baseSel==='data'){ rel = '.data/' + (sp ? sp + '/' : '') + '<?php echo htmlspecialchars($it['name']); ?>'; } else if(baseSel==='backups'){ rel = '.backups/' + (sp ? sp + '/' : '') + '<?php echo htmlspecialchars($it['name']); ?>'; } else if(baseSel==='rules'){ rel = '.rules/' + (sp ? sp + '/' : '') + '<?php echo htmlspecialchars($it['name']); ?>'; } f.value=rel; } })()">Select</button>
<?php } ?>
</div></div>
<?php } ?>
</div>
<?php } ?>
</div>
<div class="grid">
<div class="row header"><div>Key</div><div>File Path</div><div>Heading</div><div>Status</div><div>Actions</div></div>
<?php if (is_array($duplicates)) { foreach ($duplicates as $d) { ?>
<div class="row"><div><?php echo htmlspecialchars($d['key'] ?? ''); ?></div><div><?php echo htmlspecialchars($d['file_path'] ?? ''); ?></div><div><?php echo htmlspecialchars($d['heading'] ?? ''); ?></div><div><span class="badge">duplicate</span></div><div>
<form method="post" style="display:inline">
<input type="hidden" name="action" value="remove">
<input type="hidden" name="key" value="<?php echo htmlspecialchars($d['key'] ?? ''); ?>">
<button type="submit" class="secondary" onclick="return confirm('Remove this key?')">Remove</button>
</form>
</div></div>
<?php } } ?>
</div>
</div>
<?php if (!empty($GLOBALS['JM_NOTICE_SCRIPT'])) { echo '<script>' . $GLOBALS['JM_NOTICE_SCRIPT'] . '</script>'; } ?>
</body>
</html>
