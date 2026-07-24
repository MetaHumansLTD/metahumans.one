<?php
/**
 * Sync Kripz JSON credential store with Biometrics Database
 * Adds missing users as Users (default role)
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/auth_classes.php';
require_once __DIR__ . '/tenant_provisioning.php';
require_once __DIR__ . '/persona_registry.php';

function mh_sync_users_prepare_body(string $contentType): void {
    if (PHP_SAPI === 'cli') return;
    try {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    } catch (Throwable) {
    }
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, max-age=0');
}

function mh_sync_users_load_json_user_ids(MetaPasskeyAuth $auth): array {
    $jsonUsers = [];
    $userIds = method_exists($auth, 'listCredentialUserIds') ? $auth->listCredentialUserIds() : [];
    foreach ($userIds as $uid) {
        if (is_string($uid) && trim($uid) !== '') {
            $jsonUsers[trim($uid)] = true;
        }
    }
    return $jsonUsers;
}

function mh_sync_users_user_exists(PDO $db, string $username): bool {
    $username = trim($username);
    if ($username === '') return false;
    $stmt = $db->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    return (bool)$stmt->fetchColumn();
}

function mh_sync_users_has_directory(string $username): ?bool {
    $username = trim($username);
    if ($username === '') return null;
    if (!function_exists('mh_user_directory_get')) return null;
    try {
        $pdoReg = mh_persona_registry_pdo();
        $dirRow = mh_user_directory_get($pdoReg, $username);
        $dn = is_array($dirRow) && isset($dirRow['display_name']) && is_string($dirRow['display_name']) ? trim((string)$dirRow['display_name']) : '';
        if ($dn === '' || $dn === $username) return false;
        return true;
    } catch (Throwable) {
        return null;
    }
}

function mh_sync_users_guess_display_name(string $username): array {
    $u = trim($username);
    if ($u === '') return ['', null, null];
    if (strpos($u, '@') !== false) return [$u, null, null];
    $parts = preg_split('/[_\\-\\.\\s]+/', $u);
    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
    if (count($parts) < 2) {
        $camel = preg_split('/(?<=\\p{Ll})(?=\\p{Lu})/u', $u);
        $camel = is_array($camel) ? array_values(array_filter(array_map('trim', $camel), fn($p) => $p !== '')) : [];
        if (count($camel) >= 2) $parts = $camel;
    }
    if (count($parts) < 2) return [$u, null, null];
    $fn = (string)$parts[0];
    $ln = (string)$parts[count($parts) - 1];
    $display = trim($fn . ' ' . $ln);
    if ($display === '') return [$u, null, null];
    if (function_exists('mh_validate_real_first_and_surname_strict')) {
        try {
            mh_validate_real_first_and_surname_strict($fn, $ln);
        } catch (Throwable) {
            return [$display, null, null];
        }
    }
    return [$display, $fn, $ln];
}

function mh_sync_users_scan(PDO $db, MetaPasskeyAuth $auth, int $limit): array {
    $jsonUsers = mh_sync_users_load_json_user_ids($auth);
    $all = array_keys($jsonUsers);
    $missing = [];
    $deletedWithCreds = [];
    $exists = [];
    $invalid = [];
    $scanned = 0;

    foreach ($all as $username) {
        if (!is_string($username)) continue;
        $username = trim($username);
        if ($username === '') continue;
        if ($scanned >= $limit) break;
        $scanned++;

        if (function_exists('mh_username_is_reserved_prefix') && mh_username_is_reserved_prefix($username)) {
            $invalid[] = $username;
            continue;
        }
        $isDeleted = mh_sync_users_is_deleted($db, $username);
        $isExists = mh_sync_users_user_exists($db, $username);
        if ($isDeleted) {
            $deletedWithCreds[] = $username;
            continue;
        }
        if ($isExists) {
            $exists[] = $username;
            continue;
        }
        $missing[] = [
            'username' => $username,
            'has_directory' => mh_sync_users_has_directory($username),
        ];
    }

    return [
        'success' => true,
        'counts' => [
            'json_store_total' => count($all),
            'scanned' => $scanned,
            'missing_in_users' => count($missing),
            'deleted_has_creds' => count($deletedWithCreds),
            'exists_in_users' => count($exists),
            'invalid' => count($invalid),
        ],
        'missing' => $missing,
        'deleted_has_creds' => $deletedWithCreds,
        'invalid' => $invalid,
    ];
}

if (PHP_SAPI !== 'cli') {
    if (function_exists('security_startSecureSession')) {
        security_startSecureSession();
    } elseif (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }

    if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
        header('Location: /auth/login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/auth/sync_users.php'), true, 302);
        exit;
    }

    $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
    if (strpos($role, 'kripz') === false) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n";
        exit;
    }

    $isRun = false;
    if (isset($_GET['run'])) $isRun = true;
    if (isset($_POST['run'])) $isRun = true;

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$isRun) {
        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!doctype html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>User Sync</title>
          <style>
            body{margin:0;background:#0b1220;color:#e8eefc;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial}
            main{max-width:900px;margin:0 auto;padding:22px}
            .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:16px}
            .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:space-between}
            .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,212,255,.45);background:rgba(0,212,255,.12);color:#e8eefc;font-weight:900;cursor:pointer;text-decoration:none}
            .btn:disabled{opacity:.55;cursor:not-allowed}
            .muted{color:rgba(255,255,255,.72);font-size:12px}
            pre{margin:12px 0 0 0;white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.35);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:12px;min-height:220px}
            table{width:100%;border-collapse:collapse;margin-top:12px}
            th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px}
            th{color:rgba(255,255,255,.70);font-size:12px;letter-spacing:1px}
            .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);font-size:12px;color:rgba(255,255,255,.85)}
            .pill.bad{border-color:rgba(255,59,48,.35);background:rgba(255,59,48,.10)}
            .pill.ok{border-color:rgba(16,185,129,.35);background:rgba(16,185,129,.10)}
            .btn.sm{padding:7px 10px;border-radius:10px;font-weight:900}
            .btn.ghost{border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.06)}
          </style>
        </head>
        <body>
        <main>
          <div class="card">
            <div class="row">
              <div>
                <div style="font-weight:950;font-size:18px">Sync Users</div>
                <div class="muted">Sync passkey credential users into biometrics.users</div>
              </div>
              <div class="row">
                <input id="onlyUser" type="text" placeholder="Only userId (optional)" style="padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.25);color:#e8eefc;min-width:240px">
                <button id="runBtn" class="btn" type="button">Run Full Sync</button>
                <button id="runOneBtn" class="btn" type="button" style="border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.06)">Sync Only User</button>
                <button id="scanBtn" class="btn ghost" type="button">Scan Unsynced</button>
                <a class="btn" href="/control/user-manager.php" style="border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.06)">User Manager</a>
              </div>
            </div>
            <div id="scanSummary" class="muted" style="margin-top:10px"></div>
            <div id="scanWrap" style="display:none;overflow:auto;border:1px solid rgba(255,255,255,.12);border-radius:12px;margin-top:12px;background:rgba(0,0,0,.22)">
              <table>
                <thead>
                  <tr>
                    <th>UserId</th>
                    <th>Directory</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody id="scanBody"></tbody>
              </table>
            </div>
            <div id="deletedWrap" style="display:none;overflow:auto;border:1px solid rgba(255,255,255,.12);border-radius:12px;margin-top:12px;background:rgba(0,0,0,.22)">
              <div style="padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.10);font-weight:900">Deleted users still in passkey store</div>
              <table>
                <thead>
                  <tr>
                    <th>UserId</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody id="deletedBody"></tbody>
              </table>
            </div>
            <pre id="out">Ready.</pre>
          </div>
        </main>
        <script>
          (function () {
            const btn = document.getElementById('runBtn');
            const btnOne = document.getElementById('runOneBtn');
            const scanBtn = document.getElementById('scanBtn');
            const onlyUser = document.getElementById('onlyUser');
            const out = document.getElementById('out');
            const scanWrap = document.getElementById('scanWrap');
            const scanBody = document.getElementById('scanBody');
            const scanSummary = document.getElementById('scanSummary');
            const deletedWrap = document.getElementById('deletedWrap');
            const deletedBody = document.getElementById('deletedBody');
            if (!btn || !out) return;
            async function runSync(mode) {
              btn.disabled = true;
              if (btnOne) btnOne.disabled = true;
              if (scanBtn) scanBtn.disabled = true;
              out.textContent = 'Running...';
              try {
                const form = new URLSearchParams();
                form.set('run', '1');
                if (mode === 'one') {
                  const v = (onlyUser && onlyUser.value) ? String(onlyUser.value).trim() : '';
                  if (!v) {
                    out.textContent = 'Please enter a userId.';
                    return;
                  }
                  form.set('only_user', v);
                }
                const r = await fetch(location.href, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: form.toString(),
                  credentials: 'same-origin'
                });
                const t = await r.text();
                out.textContent = t || 'No output.';
              } catch (e) {
                out.textContent = 'Failed: ' + String(e && e.message ? e.message : e);
              } finally {
                btn.disabled = false;
                if (btnOne) btnOne.disabled = false;
                if (scanBtn) scanBtn.disabled = false;
              }
            }
            btn.addEventListener('click', () => runSync('full'));
            if (btnOne) btnOne.addEventListener('click', () => runSync('one'));

            function pill(text, kind) {
              const s = document.createElement('span');
              s.className = 'pill' + (kind ? (' ' + kind) : '');
              s.textContent = text;
              return s;
            }

            async function callAction(action, data) {
              const form = new URLSearchParams();
              form.set('action', action);
              if (data) {
                for (const k of Object.keys(data)) form.set(k, String(data[k]));
              }
              const r = await fetch(location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: form.toString(),
                credentials: 'same-origin'
              });
              const d = await r.json();
              return d;
            }

            async function scan() {
              if (scanBtn) scanBtn.disabled = true;
              if (scanSummary) scanSummary.textContent = 'Scanning...';
              if (scanWrap) scanWrap.style.display = 'none';
              if (deletedWrap) deletedWrap.style.display = 'none';
              try {
                const d = await callAction('scan', {});
                if (!d || !d.success) {
                  if (scanSummary) scanSummary.textContent = 'Scan failed.';
                  return;
                }
                const c = d.counts || {};
                if (scanSummary) {
                  scanSummary.textContent = 'JSON store: ' + (c.json_store_total || 0) +
                    ' · Scanned: ' + (c.scanned || 0) +
                    ' · Missing users: ' + (c.missing_in_users || 0) +
                    ' · Deleted-with-creds: ' + (c.deleted_has_creds || 0) +
                    ' · Invalid: ' + (c.invalid || 0);
                }
                if (scanBody) scanBody.innerHTML = '';
                const missing = Array.isArray(d.missing) ? d.missing : [];
                if (missing.length > 0 && scanWrap) scanWrap.style.display = 'block';
                for (const row of missing) {
                  const u = row && row.username ? String(row.username) : '';
                  if (!u) continue;
                  const tr = document.createElement('tr');
                  const tdU = document.createElement('td'); tdU.textContent = u;
                  const tdD = document.createElement('td');
                  const hd = row && Object.prototype.hasOwnProperty.call(row, 'has_directory') ? row.has_directory : null;
                  if (hd === true) tdD.appendChild(pill('yes', 'ok'));
                  else if (hd === false) tdD.appendChild(pill('no', 'bad'));
                  else tdD.appendChild(pill('unknown', ''));
                  const tdS = document.createElement('td'); tdS.appendChild(pill('missing', 'bad'));
                  const tdA = document.createElement('td');
                  const b1 = document.createElement('button');
                  b1.className = 'btn sm'; b1.type = 'button'; b1.textContent = 'Sync';
                  b1.onclick = function () { if (onlyUser) onlyUser.value = u; runSync('one'); };
                  const b2 = document.createElement('button');
                  b2.className = 'btn sm ghost'; b2.type = 'button'; b2.textContent = 'Purge Passkeys';
                  b2.style.marginLeft = '8px';
                  b2.onclick = async function () {
                    out.textContent = 'Purging passkeys for ' + u + '...';
                    try {
                      const resp = await callAction('purge_credentials', { only_user: u });
                      out.textContent = resp && resp.success ? ('Purged passkeys for ' + u) : ('Purge failed for ' + u);
                      scan();
                    } catch (e) {
                      out.textContent = 'Purge failed for ' + u;
                    }
                  };
                  tdA.appendChild(b1);
                  tdA.appendChild(b2);
                  tr.appendChild(tdU);
                  tr.appendChild(tdD);
                  tr.appendChild(tdS);
                  tr.appendChild(tdA);
                  scanBody.appendChild(tr);
                }

                if (deletedBody) deletedBody.innerHTML = '';
                const del = Array.isArray(d.deleted_has_creds) ? d.deleted_has_creds : [];
                if (del.length > 0 && deletedWrap) deletedWrap.style.display = 'block';
                for (const u of del) {
                  const uid = String(u || '');
                  if (!uid) continue;
                  const tr = document.createElement('tr');
                  const tdU = document.createElement('td'); tdU.textContent = uid;
                  const tdA = document.createElement('td');
                  const b2 = document.createElement('button');
                  b2.className = 'btn sm ghost'; b2.type = 'button'; b2.textContent = 'Purge Passkeys';
                  b2.onclick = async function () {
                    out.textContent = 'Purging passkeys for ' + uid + '...';
                    try {
                      const resp = await callAction('purge_credentials', { only_user: uid });
                      out.textContent = resp && resp.success ? ('Purged passkeys for ' + uid) : ('Purge failed for ' + uid);
                      scan();
                    } catch (e) {
                      out.textContent = 'Purge failed for ' + uid;
                    }
                  };
                  tdA.appendChild(b2);
                  tr.appendChild(tdU);
                  tr.appendChild(tdA);
                  deletedBody.appendChild(tr);
                }
              } finally {
                if (scanBtn) scanBtn.disabled = false;
              }
            }

            if (scanBtn) scanBtn.addEventListener('click', scan);
            scan();
          })();
        </script>
        </body>
        </html>
        <?php
        exit;
    }
}

if (PHP_SAPI !== 'cli' && isset($_POST['action']) && is_string($_POST['action'])) {
    $action = trim((string)$_POST['action']);
    if ($action === 'scan') {
        try {
            cue_autoload('database');
            $db = database_getConnectionById('biometrics');
            $auth = new MetaPasskeyAuth();
            $res = mh_sync_users_scan($db, $auth, 2000);
            mh_sync_users_prepare_body('application/json; charset=UTF-8');
            echo json_encode($res, JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            mh_sync_users_prepare_body('application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
    if ($action === 'purge_credentials') {
        $only = isset($_POST['only_user']) ? trim((string)$_POST['only_user']) : '';
        if ($only === '') {
            mh_sync_users_prepare_body('application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'missing_only_user'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        try {
            $auth = new MetaPasskeyAuth();
            if (method_exists($auth, 'deleteUserCredentials')) {
                $auth->deleteUserCredentials($only);
            }
            mh_sync_users_prepare_body('application/json; charset=UTF-8');
            echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES);
            exit;
        } catch (Throwable $e) {
            mh_sync_users_prepare_body('application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

mh_sync_users_prepare_body('text/plain; charset=UTF-8');

echo "Starting Sync Process...\n";
echo "========================\n";

// 1. Load JSON Users (Credentials)
$auth = new MetaPasskeyAuth();
$jsonUsers = [];

try {
    $userIds = method_exists($auth, 'listCredentialUserIds') ? $auth->listCredentialUserIds() : [];
    foreach ($userIds as $uid) {
        if (is_string($uid) && trim($uid) !== '') {
            $jsonUsers[trim($uid)] = true;
        }
    }
    echo "Found " . count($jsonUsers) . " users in JSON credential store.\n";
} catch (Exception $e) {
    echo "Error accessing credentials: " . $e->getMessage() . "\n";
    exit(1);
}

$onlyUser = '';
if (isset($_POST['only_user'])) $onlyUser = (string)$_POST['only_user'];
if ($onlyUser === '' && isset($_GET['only_user'])) $onlyUser = (string)$_GET['only_user'];
$onlyUser = trim($onlyUser);
$isOnlyUserMode = ($onlyUser !== '');
if ($onlyUser !== '') {
    $foundKey = null;
    foreach (array_keys($jsonUsers) as $k) {
        if (is_string($k) && strcasecmp($k, $onlyUser) === 0) {
            $foundKey = $k;
            break;
        }
    }
    if ($foundKey === null) {
        echo "Requested only_user '{$onlyUser}' not found in JSON credential store.\n";
        exit;
    }
    $jsonUsers = [$foundKey => true];
    echo "Filtered to only_user: {$foundKey}\n";
}

if (empty($jsonUsers)) {
    echo "No users found in JSON store. Nothing to sync.\n";
    exit;
}

// 2. Connect to Database
try {
    cue_autoload('database');
    $db = database_getConnectionById('biometrics');
    echo "Connected to database.\n";

} catch (Exception $e) {
    echo "Database Connection Error: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL,
        email VARCHAR(255) DEFAULT NULL,
        name VARCHAR(255) DEFAULT NULL,
        real_first_name VARCHAR(64) DEFAULT NULL,
        real_last_name VARCHAR(64) DEFAULT NULL,
        persona_name VARCHAR(255) DEFAULT NULL,
        tenant_id VARCHAR(255) DEFAULT NULL,
        role VARCHAR(255) DEFAULT 'Users',
        permissions TEXT DEFAULT NULL,
        pin_hash VARCHAR(255) DEFAULT NULL,
        tokens INT DEFAULT 0,
        token_usage INT DEFAULT 0,
        genesis_status INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_users_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    echo "Schema Error (users): " . $e->getMessage() . "\n";
    exit(1);
}

// 3. Sync Users
$addedCount = 0;
$repairedCount = 0;
$skippedDeletedCount = 0;
$skippedNoDirectoryCount = 0;
$repairedGlobalCount = 0;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS mh_deleted_users (
        username VARCHAR(255) NOT NULL PRIMARY KEY,
        deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_by VARCHAR(255) DEFAULT NULL,
        reason VARCHAR(255) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable) {
}

function mh_sync_users_is_deleted(PDO $db, string $username): bool {
    $username = trim($username);
    if ($username === '') return false;
    try {
        $st = $db->prepare("SELECT 1 FROM mh_deleted_users WHERE username = ? LIMIT 1");
        $st->execute([$username]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function mh_sync_users_parse_name(string $displayName, string $username): array {
    $displayName = trim($displayName);
    $username = trim($username);
    if ($displayName === '' || $displayName === $username) return [null, null, null];
    if (strpos($displayName, '@') !== false) return [null, null, null];
    $parts = preg_split('/\s+/', $displayName);
    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
    if (count($parts) < 2) return [null, null, null];
    $fn = (string)$parts[0];
    $ln = (string)$parts[count($parts) - 1];
    if ($fn === '' || $ln === '') return [null, null, null];
    if (function_exists('mh_validate_real_first_and_surname_strict')) {
        try {
            mh_validate_real_first_and_surname_strict($fn, $ln);
        } catch (Throwable) {
            return [null, null, null];
        }
    }
    return [$displayName, $fn, $ln];
}

function mh_sync_users_best_display(PDO $pdoReg, PDO $db, string $username): array {
    $username = trim($username);
    $dn = '';
    try {
        if (function_exists('mh_user_directory_get')) {
            $dirRow = mh_user_directory_get($pdoReg, $username);
            $dn = is_array($dirRow) && isset($dirRow['display_name']) && is_string($dirRow['display_name']) ? trim((string)$dirRow['display_name']) : '';
        }
    } catch (Throwable) {
        $dn = '';
    }
    if ($dn !== '' && $dn !== $username) {
        return mh_sync_users_parse_name($dn, $username);
    }
    try {
        $curStmt = $db->prepare("SELECT name FROM users WHERE username = ? LIMIT 1");
        $curStmt->execute([$username]);
        $curName = $curStmt->fetchColumn();
        $curName = is_string($curName) ? trim((string)$curName) : '';
        if ($curName !== '' && $curName !== $username) {
            return mh_sync_users_parse_name($curName, $username);
        }
    } catch (Throwable) {
    }
    return [null, null, null];
}

foreach (array_keys($jsonUsers) as $username) {
    try {
        if (is_string($username) && function_exists('mh_username_is_reserved_prefix') && mh_username_is_reserved_prefix($username)) {
            echo "[SKIP] User '$username' is reserved/invalid. Skipping.\n";
            continue;
        }
        if (mh_sync_users_is_deleted($db, (string)$username)) {
            echo "[SKIP] User '$username' is marked deleted. Skipping.\n";
            $skippedDeletedCount++;
            continue;
        }

        // Check if user exists
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if ($stmt->fetch()) {
            echo "[SKIP] User '$username' already exists in database.\n";
            try {
                $tenantId = 'user:' . $username;
                if (function_exists('mh_provision_tenant_integrations')) {
                    mh_provision_tenant_integrations($tenantId, $username);
                }
            } catch (Throwable $e) {}
            try {
                $pdoReg = mh_persona_registry_pdo();
                [$bestName, $fn, $ln] = mh_sync_users_best_display($pdoReg, $db, (string)$username);
                if ($bestName !== null && $fn !== null && $ln !== null) {
                    $curStmt = $db->prepare("SELECT name, real_first_name, real_last_name FROM users WHERE username = ? LIMIT 1");
                    $curStmt->execute([(string)$username]);
                    $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
                    $curName = is_array($cur) && isset($cur['name']) ? trim((string)$cur['name']) : '';
                    $curFn = is_array($cur) && isset($cur['real_first_name']) ? trim((string)$cur['real_first_name']) : '';
                    $curLn = is_array($cur) && isset($cur['real_last_name']) ? trim((string)$cur['real_last_name']) : '';
                    $needName = ($curName === '' || $curName === (string)$username);
                    $needFn = ($curFn === '');
                    $needLn = ($curLn === '');
                    if ($needName || $needFn || $needLn) {
                        $upd = $db->prepare("UPDATE users SET
                            name = IF(COALESCE(NULLIF(name,''),'')='' OR name = username, ?, name),
                            real_first_name = IF(COALESCE(NULLIF(real_first_name,''),'')='', ?, real_first_name),
                            real_last_name = IF(COALESCE(NULLIF(real_last_name,''),'')='', ?, real_last_name)
                            WHERE username = ? LIMIT 1");
                        $upd->execute([(string)$bestName, (string)$fn, (string)$ln, (string)$username]);
                        $repairedCount++;
                    }
                }
            } catch (Throwable $e) {}
        } else {
            echo "[ADD] User '$username' is missing. Adding as Users...\n";
            
            // Prepare data
            $displayName = $username;
            $firstName = null;
            $surname = null;
            $personaName = 'MH-' . $username;
            $tenantId = 'user:' . $username;

            try {
                $pdoReg = mh_persona_registry_pdo();
                if (function_exists('mh_user_directory_get')) {
                    $dirRow = mh_user_directory_get($pdoReg, (string)$username);
                    $dn = is_array($dirRow) && isset($dirRow['display_name']) && is_string($dirRow['display_name']) ? trim((string)$dirRow['display_name']) : '';
                    if ($dn === '' || $dn === (string)$username) {
                        if ($isOnlyUserMode) {
                            [$bestName, $fn, $ln] = mh_sync_users_guess_display_name((string)$username);
                            if ($bestName !== '') {
                                $displayName = $bestName;
                                $firstName = $fn;
                                $surname = $ln;
                            }
                        } else {
                            echo "[SKIP] User '$username' has no user directory entry. Skipping add.\n";
                            $skippedNoDirectoryCount++;
                            continue;
                        }
                    }
                    if ($dn !== '' && $dn !== (string)$username) {
                        [$bestName, $fn, $ln] = mh_sync_users_parse_name($dn, (string)$username);
                        if ($bestName !== null) {
                            $displayName = $bestName;
                            $firstName = $fn;
                            $surname = $ln;
                        } else {
                            $displayName = $dn;
                            $firstName = null;
                            $surname = null;
                        }
                    }
                } else {
                    if ($isOnlyUserMode) {
                        [$bestName, $fn, $ln] = mh_sync_users_guess_display_name((string)$username);
                        if ($bestName !== '') {
                            $displayName = $bestName;
                            $firstName = $fn;
                            $surname = $ln;
                        }
                    } else {
                        echo "[SKIP] User '$username' has no user directory support. Skipping add.\n";
                        $skippedNoDirectoryCount++;
                        continue;
                    }
                }
            } catch (Throwable $e) {}
            
            // 1. Ensure tenant_id column exists in users
            try {
                $stmtCols = $db->query("SHOW COLUMNS FROM users LIKE 'tenant_id'");
                if ($stmtCols->rowCount() === 0) {
                    $db->exec("ALTER TABLE users ADD COLUMN tenant_id VARCHAR(255) DEFAULT NULL AFTER persona_name");
                }
            } catch (Exception $e) { /* Ignore */ }

            // 2. Insert User
            $stmtInsert = $db->prepare("INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, tenant_id, role, created_at) VALUES (?, ?, ?, ?, ?, ?, 'Users', NOW())");
            $stmtInsert->execute([$username, $displayName, $firstName, $surname, $personaName, $tenantId]);
            
            try {
                $pdoReg = mh_persona_registry_pdo();
                mh_persona_registry_claim($pdoReg, $username, $personaName);
                if (function_exists('mh_user_directory_upsert')) {
                    mh_user_directory_upsert($pdoReg, $username, $displayName, $firstName, $surname, $personaName, null);
                }
            } catch (Throwable $e) {
            }

            $dbConfigId = mh_resolve_tenant_db_config_id($tenantId);
            if (!is_string($dbConfigId) || $dbConfigId === '') {
                mh_provision_tenant_storage($tenantId);
                $prov = mh_provision_tenant_database($tenantId);
                $dbConfigId = is_array($prov) ? (string)($prov['db_config_id'] ?? '') : '';
            }
            if ($dbConfigId !== '') {
                try {
                    $pdoTenant = database_getConnectionById($dbConfigId);
                    if ($pdoTenant instanceof PDO) {
                    }
                } catch (Throwable $e) {
                }
            }

            echo "  -> Successfully added user and persona.\n";
            try {
                if (function_exists('mh_provision_tenant_integrations')) {
                    mh_provision_tenant_integrations($tenantId, $username);
                }
            } catch (Throwable $e) {}
            $addedCount++;
        }
        
    } catch (Exception $e) {
        echo "[ERROR] Failed to process user '$username': " . $e->getMessage() . "\n";
    }
}

try {
    $pdoReg = mh_persona_registry_pdo();
    $stmt = $db->query("SELECT username FROM users WHERE COALESCE(NULLIF(real_first_name,''),'')='' OR COALESCE(NULLIF(real_last_name,''),'')='' LIMIT 500");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $u = is_array($r) && isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u === '') continue;
            [$bestName, $fn, $ln] = mh_sync_users_best_display($pdoReg, $db, $u);
            if ($bestName !== null && $fn !== null && $ln !== null) {
                $upd = $db->prepare("UPDATE users SET
                    name = IF(COALESCE(NULLIF(name,''),'')='' OR name = username, ?, name),
                    real_first_name = IF(COALESCE(NULLIF(real_first_name,''),'')='', ?, real_first_name),
                    real_last_name = IF(COALESCE(NULLIF(real_last_name,''),'')='', ?, real_last_name)
                    WHERE username = ? LIMIT 1");
                $upd->execute([(string)$bestName, (string)$fn, (string)$ln, (string)$u]);
                if ((int)$upd->rowCount() > 0) {
                    $repairedGlobalCount++;
                }
            }
        }
    }
} catch (Throwable $e) {
}

echo "\nSync Complete.\n";
echo "Added: $addedCount\n";
echo "Repaired (during sync): $repairedCount\n";
echo "Repaired (global): $repairedGlobalCount\n";
echo "Skipped deleted: $skippedDeletedCount\n";
echo "Skipped no directory: $skippedNoDirectoryCount\n";
