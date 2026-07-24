<?php
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim((string)$_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
mh_kripz_require('database_allowlist', $isAjax);

if (function_exists('cue_autoload')) {
    cue_autoload('security');
    cue_autoload('theme');
    cue_autoload('paths');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$csrfKey = 'mh_db_allowlist_csrf';
$csrf = isset($_SESSION[$csrfKey]) && is_string($_SESSION[$csrfKey]) ? (string)$_SESSION[$csrfKey] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION[$csrfKey] = $csrf;
}

$cfgDir = '/data/config';
try {
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $p0 = $paths && method_exists($paths, 'getConfigPath') ? (string)$paths->getConfigPath() : '';
    if ($p0 !== '') $cfgDir = $p0;
} catch (Throwable $e) {}
$cfgDir = rtrim($cfgDir, '/');
$allowlistFile = $cfgDir . '/biometrics-tenant-allowlist.json';

function mh_db_allowlist_read(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return ['schema_version' => '1.0.0', 'allowed_exact' => []];
    }
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['schema_version' => '1.0.0', 'allowed_exact' => []];
    }
    $items = $decoded['allowed_exact'] ?? $decoded['allowed_paths'] ?? [];
    $out = [];
    if (is_array($items)) {
        foreach ($items as $k => $v) {
            if (is_string($v)) {
                $p = trim($v);
                if ($p !== '') $out[$p] = true;
                continue;
            }
            if (is_string($k) && ($v === true || $v === 1 || $v === '1')) {
                $p = trim($k);
                if ($p !== '') $out[$p] = true;
            }
        }
    }
    ksort($out);
    return [
        'schema_version' => is_string($decoded['schema_version'] ?? null) ? (string)$decoded['schema_version'] : '1.0.0',
        'allowed_exact' => $out,
    ];
}

function mh_db_allowlist_write(string $file, array $allowedExact): bool
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    ksort($allowedExact);
    $payload = [
        'schema_version' => '1.0.0',
        'allowed_exact' => array_keys($allowedExact),
        'updated_at' => gmdate('c'),
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') return false;
    return @file_put_contents($file, $json . "\n", LOCK_EX) !== false;
}

function mh_db_allowlist_normalize_path(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';
    if ($path[0] !== '/') $path = '/' . $path;
    $path = preg_replace('#/+#', '/', $path);
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    return $path;
}

$message = '';
$state = mh_db_allowlist_read($allowlistFile);
$allowedExact = is_array($state['allowed_exact'] ?? null) ? (array)$state['allowed_exact'] : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postedCsrf)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'csrf'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $message = 'Invalid request.';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($isAjax && $action === 'add_ajax') {
            $p = isset($_POST['path']) ? mh_db_allowlist_normalize_path((string)$_POST['path']) : '';
            if ($p === '' || $p === '/') {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'invalid_path'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            $allowedExact[$p] = true;
            $ok = mh_db_allowlist_write($allowlistFile, $allowedExact);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => $ok, 'path' => $p], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($isAjax && $action === 'remove_ajax') {
            $p = isset($_POST['path']) ? mh_db_allowlist_normalize_path((string)$_POST['path']) : '';
            if ($p === '' || $p === '/') {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'invalid_path'], JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (isset($allowedExact[$p])) {
                unset($allowedExact[$p]);
            }
            $ok = mh_db_allowlist_write($allowlistFile, $allowedExact);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => $ok, 'path' => $p], JSON_UNESCAPED_SLASHES);
            exit;
        }
        if ($action === 'add') {
            $paths = isset($_POST['paths']) && is_array($_POST['paths']) ? $_POST['paths'] : [];
            $added = 0;
            foreach ($paths as $p) {
                if (!is_string($p)) continue;
                $p = mh_db_allowlist_normalize_path($p);
                if ($p === '' || $p === '/') continue;
                $allowedExact[$p] = true;
                $added++;
            }
            if (mh_db_allowlist_write($allowlistFile, $allowedExact)) {
                $message = $added > 0 ? 'Allowlist updated.' : 'No paths added.';
            } else {
                $message = 'Failed to save allowlist.';
            }
        } elseif ($action === 'remove') {
            $p = isset($_POST['path']) ? mh_db_allowlist_normalize_path((string)$_POST['path']) : '';
            if ($p !== '' && isset($allowedExact[$p])) {
                unset($allowedExact[$p]);
                if (mh_db_allowlist_write($allowlistFile, $allowedExact)) {
                    $message = 'Removed.';
                } else {
                    $message = 'Failed to save allowlist.';
                }
            } else {
                $message = 'Path not found.';
            }
        } elseif ($action === 'add_manual') {
            $p = isset($_POST['path']) ? mh_db_allowlist_normalize_path((string)$_POST['path']) : '';
            if ($p === '' || $p === '/') {
                $message = 'Invalid path.';
            } else {
                $allowedExact[$p] = true;
                if (mh_db_allowlist_write($allowlistFile, $allowedExact)) {
                    $message = 'Added.';
                } else {
                    $message = 'Failed to save allowlist.';
                }
            }
        }
    }
    $state = mh_db_allowlist_read($allowlistFile);
    $allowedExact = is_array($state['allowed_exact'] ?? null) ? (array)$state['allowed_exact'] : [];
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$qNorm = strtolower($q);
$results = [];
$scanRoots = [
    dirname(dirname(__DIR__)) . '/hub',
    dirname(dirname(__DIR__)) . '/studio',
];
foreach ($scanRoots as $root) {
    if (!is_dir($root)) continue;
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f instanceof SplFileInfo) continue;
            if (!$f->isFile()) continue;
            $name = $f->getFilename();
            if (substr($name, -4) !== '.php') continue;
            $full = $f->getPathname();
            $rel = str_replace(dirname(dirname(__DIR__)), '', $full);
            $rel = mh_db_allowlist_normalize_path($rel);
            if ($rel === '' || $rel === '/') continue;
            if ($qNorm !== '' && strpos(strtolower($rel), $qNorm) === false) continue;
            $results[] = $rel;
            if (count($results) >= 300) break 2;
        }
    } catch (Throwable $e) {
    }
}
sort($results);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Allowlist | Meta Humans</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        body.db-allowlist main.main-content { color: rgba(255,255,255,0.92); }
        body.db-allowlist .wrap { max-width: 1100px; margin: 0 auto; padding: 28px 18px; }
        body.db-allowlist h1 { font-family: 'Orbitron', sans-serif; color: var(--theme-primary, #00d4ff); letter-spacing: 2px; margin: 0 0 14px; }
        body.db-allowlist .card { background: rgba(255,255,255,0.04); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 16px; backdrop-filter: blur(10px); margin-bottom: 16px; }
        body.db-allowlist .muted { color: rgba(255,255,255,0.72); font-size: 0.95rem; }
        body.db-allowlist .btn { display: inline-flex; gap: 8px; align-items: center; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.35); background: rgba(0, 212, 255, 0.06); color: rgba(255,255,255,0.92); text-decoration: none; cursor: pointer; font-weight: 700; }
        body.db-allowlist .btn.primary { background: rgba(0, 212, 255, 0.16); }
        body.db-allowlist .btn.danger { border-color: rgba(244,63,94,0.55); background: rgba(244,63,94,0.08); }
        body.db-allowlist .form-row { display:flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
        body.db-allowlist .form-field { flex: 1; min-width: 280px; }
        body.db-allowlist input[type="text"] { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(255,255,255,0.03); color: #fff; }
        body.db-allowlist button.btn { position: static !important; white-space: nowrap; }
        body.db-allowlist table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        body.db-allowlist th, body.db-allowlist td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(0, 212, 255, 0.14); vertical-align: top; }
        body.db-allowlist th { color: var(--theme-primary, #00d4ff); font-weight: 700; font-size: 0.9rem; }
        body.db-allowlist code { color: rgba(255,255,255,0.9); }
    </style>
</head>
<body class="db-allowlist">
<?php if (function_exists('renderGlobalHeader')) { renderGlobalHeader(); } ?>
<main class="main-content">
    <div class="wrap">
        <div class="card">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;">
                <div>
                    <h1>Database Allowlist</h1>
                    <div class="muted">Controls which tenant-scoped pages are allowed to access the biometrics database.</div>
                </div>
                <div class="muted" style="word-break:break-all;">File: <code><?php echo htmlspecialchars($allowlistFile); ?></code></div>
            </div>
        </div>

        <?php if ($message !== ''): ?>
            <div class="card"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <div id="saveStatus" class="card muted" style="display:none;"></div>

        <div class="card">
            <div style="font-weight:800;margin-bottom:8px;">Add Manually</div>
            <form method="POST" class="form-row">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="add_manual">
                <div class="form-field">
                    <div class="muted" style="margin-bottom:8px;">Example: <code>/hub/settings.php</code></div>
                    <input type="text" name="path" value="" placeholder="/hub/..." />
                </div>
                <div>
                    <button class="btn primary" type="submit">Add</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div style="font-weight:800;margin-bottom:8px;">Search Pages</div>
            <form method="GET" class="form-row">
                <div class="form-field">
                    <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Search /hub and /studio php paths..." />
                </div>
                <div>
                    <button class="btn primary" type="submit">Search</button>
                </div>
            </form>

            <form method="POST" style="margin-top:12px;">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="add">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 42px;"></th>
                            <th>Path</th>
                            <th style="width: 120px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $p): ?>
                            <?php $isAllowed = isset($allowedExact[$p]); ?>
                            <tr>
                                <td><input type="checkbox" class="allowToggle" name="paths[]" value="<?php echo htmlspecialchars($p); ?>" data-path="<?php echo htmlspecialchars($p); ?>" <?php echo $isAllowed ? 'disabled checked' : ''; ?> /></td>
                                <td><code><?php echo htmlspecialchars($p); ?></code></td>
                                <td class="muted" data-status-for="<?php echo htmlspecialchars($p); ?>"><?php echo $isAllowed ? 'Allowed' : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($results)): ?>
                            <tr><td colspan="3" class="muted">No results.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top: 10px;">
                    <button class="btn primary" type="submit">Add Selected</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div style="font-weight:800;margin-bottom:8px;">Currently Allowed</div>
            <table>
                <thead>
                    <tr>
                        <th>Path</th>
                        <th style="width: 140px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_keys($allowedExact) as $p): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars((string)$p); ?></code></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="path" value="<?php echo htmlspecialchars((string)$p); ?>">
                                    <button class="btn danger" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allowedExact)): ?>
                        <tr><td colspan="2" class="muted">No allowlisted paths.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php if (function_exists('renderGlobalFooter')) { renderGlobalFooter(); } ?>
<script>
(() => {
  const CSRF = <?php echo json_encode($csrf); ?>;
  const statusEl = document.getElementById('saveStatus');
  const show = (msg) => {
    if (!statusEl) return;
    statusEl.textContent = msg;
    statusEl.style.display = 'block';
  };

  const post = (params) => {
    const fd = new URLSearchParams();
    for (const k in params) fd.set(k, params[k]);
    return fetch(window.location.pathname, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: fd.toString()
    }).then(r => r.json());
  };

  document.querySelectorAll('input.allowToggle[data-path]').forEach((cb) => {
    cb.addEventListener('change', () => {
      const p = cb.getAttribute('data-path') || '';
      if (!p) return;
      if (!cb.checked) {
        cb.checked = true;
        return;
      }
      cb.disabled = true;
      show('Saving...');
      post({ csrf: CSRF, action: 'add_ajax', path: p })
        .then((d) => {
          if (!d || !d.success) {
            cb.disabled = false;
            cb.checked = false;
            show('Failed to save allowlist.');
            return;
          }
          const cell = document.querySelector('td[data-status-for="' + CSS.escape(p) + '"]');
          if (cell) cell.textContent = 'Allowed';
          show('Saved.');
        })
        .catch(() => {
          cb.disabled = false;
          cb.checked = false;
          show('Failed to save allowlist.');
        });
    });
  });
})();
</script>
</body>
</html>
