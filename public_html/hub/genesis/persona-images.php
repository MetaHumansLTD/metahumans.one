<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

mh_widget_start_session();
$currentUser = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($currentUser === '') {
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $fetchDest = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    $wantsHtmlDocument = str_contains($accept, 'text/html') || $fetchDest === 'document';
    if ($wantsHtmlDocument) {
        $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/hub/genesis/persona-images.php';
        if ($redirect === '' || $redirect[0] !== '/') {
            $redirect = '/hub/genesis/persona-images.php';
        }
        header('Location: /auth/login.php?redirect=' . rawurlencode($redirect), true, 302);
        exit;
    }
}

$ctx = mh_widget_require_auth();
$tenantId = (string)($ctx['tenant_id'] ?? '');
$tenantSafe = mh_widget_sanitize_id(strtolower($tenantId));

if ($tenantSafe === '' || $tenantSafe === 'unknown') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'invalid_tenant_id';
    exit;
}

function mh_pi_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'default';
}

function mh_pi_find_avatar(string $tenantSafe, string $persona): array
{
    $persona = trim((string)$persona);
    $personaId = mh_pi_safe_id($persona);
    $root = '/data/tenants/' . $tenantSafe . '/personas';
    $candidates = [];
    if ($personaId !== '') $candidates[] = $root . '/' . $personaId;
    if (is_dir($root)) {
        $entries = scandir($root);
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if (!is_string($e) || $e === '.' || $e === '..') continue;
                $dir = $root . '/' . $e;
                if (!is_dir($dir)) continue;
                $manifest = $dir . '/assets/manifest.json';
                $pname = '';
                if (is_file($manifest)) {
                    $raw = @file_get_contents($manifest);
                    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                    if (is_array($j)) {
                        $pn = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
                        if ($pn !== '') $pname = $pn;
                    }
                }
                $pid = mh_pi_safe_id($e);
                if ($pid === $personaId) $candidates[] = $dir;
                if ($pname !== '' && mh_pi_safe_id($pname) === $personaId) $candidates[] = $dir;
                if ($pname !== '' && strcasecmp($pname, $persona) === 0) $candidates[] = $dir;
            }
        }
    }
    $candidates = array_values(array_unique($candidates));
    foreach ($candidates as $dir) {
        $path = $dir . '/assets/images/normalized/avatar.png';
        if (is_file($path) && filesize($path) > 0) {
            return ['persona_dir' => $dir, 'avatar_path' => $path, 'exists' => true, 'size' => (int)@filesize($path)];
        }
    }
    $fallback = $root . '/master/assets/images/normalized/avatar.png';
    if (is_file($fallback) && filesize($fallback) > 0) {
        return ['persona_dir' => $root . '/master', 'avatar_path' => $fallback, 'exists' => true, 'size' => (int)@filesize($fallback)];
    }
    $defaultPath = $root . '/' . $personaId . '/assets/images/normalized/avatar.png';
    return ['persona_dir' => $root . '/' . $personaId, 'avatar_path' => $defaultPath, 'exists' => false, 'size' => 0];
}

$persona = isset($_GET['persona']) ? trim((string)$_GET['persona']) : '';
$debug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

if ($persona !== '' || $debug) {
    if ($persona === '') $persona = 'master';
    $found = mh_pi_find_avatar($tenantSafe, $persona);
    if ($debug) {
        mh_widget_json([
            'success' => true,
            'tenant_id' => $tenantId,
            'tenant_safe' => $tenantSafe,
            'persona' => $persona,
            'persona_id' => mh_pi_safe_id($persona),
            'persona_dir' => (string)($found['persona_dir'] ?? ''),
            'avatar_path' => (string)($found['avatar_path'] ?? ''),
            'avatar_exists' => (bool)($found['exists'] ?? false),
            'avatar_size' => (int)($found['size'] ?? 0),
        ]);
        exit;
    }

    $avatarPath = (string)($found['avatar_path'] ?? '');
    if ($avatarPath !== '' && is_file($avatarPath) && filesize($avatarPath) > 0) {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, max-age=0');
        header('Content-Disposition: inline');
        readfile($avatarPath);
        exit;
    }

    http_response_code(200);
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"><rect width="100%" height="100%" fill="#0b0b0b"/><text x="50%" y="50%" fill="#9aa" font-family="Arial" font-size="18" text-anchor="middle">Persona image missing - upload in Edit</text></svg>';
    exit;
}

$root = '/data/tenants/' . $tenantSafe . '/personas';
$items = [];
if (is_dir($root)) {
    $entries = scandir($root);
    if (is_array($entries)) {
        foreach ($entries as $e) {
            if (!is_string($e) || $e === '.' || $e === '..') continue;
            $dir = $root . '/' . $e;
            if (!is_dir($dir)) continue;
            $pid = mh_pi_safe_id($e);
            $manifest = $dir . '/assets/manifest.json';
            $pname = $pid;
            $createdAt = '';
            if (is_file($manifest)) {
                $raw = @file_get_contents($manifest);
                $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                if (is_array($j)) {
                    $pn = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
                    if ($pn !== '') $pname = $pn;
                    $ca = isset($j['created_at']) ? trim((string)$j['created_at']) : '';
                    if ($ca !== '') $createdAt = $ca;
                }
            }
            if ($createdAt === '') $createdAt = gmdate('c', (int)@filemtime($dir));
            $items[] = [
                'persona_id' => $pid,
                'persona_name' => $pname,
                'created_at' => $createdAt,
            ];
        }
    }
}
usort($items, function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Persona Images</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body { background: #0a0a0a; color: #00d4ff; font-family: 'Rajdhani', sans-serif; margin: 0; }
        .wrap { display:flex; justify-content:center; padding: 16px; }
        .container { width: 100%; max-width: 1100px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 16px; }
        h1 { margin: 0 0 8px; font-weight: 300; letter-spacing: 2px; text-align:center; }
        .sub { margin: 0 0 12px; color: rgba(255,255,255,0.7); text-align:center; }
        .toolbar { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; justify-content: space-between; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .create { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; width: 100%; }
        .create input { flex: 1; min-width: 220px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 10px 12px; color: rgba(255,255,255,0.9); font-size: 14px; outline: none; box-sizing: border-box; }
        .meta { display:grid; grid-template-columns: 1fr; gap: 6px; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 12px; margin-bottom: 14px; }
        .meta div { color: rgba(255,255,255,0.8); font-size: 13px; word-break: break-word; }
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .card { background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; overflow:hidden; }
        .img { aspect-ratio: 1 / 1; background:#000; border-bottom: 1px solid rgba(255,255,255,0.12); }
        .img img { width:100%; height:100%; object-fit:contain; display:block; }
        .body { padding: 12px; }
        .label { color: rgba(255,255,255,0.55); font-size: 12px; margin-top: 6px; }
        .value { color: rgba(255,255,255,0.9); font-size: 14px; word-break: break-word; }
        .actions { display:flex; gap: 10px; justify-content:center; margin-top: 12px; flex-wrap: wrap; }
        .btn { background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%); border: none; padding: 10px 14px; color: white; font-weight: bold; border-radius: 10px; cursor: pointer; font-size: 13px; text-decoration:none; display:inline-block; }
        .btn.secondary { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); }
        .btn.danger { background: rgba(255,90,122,0.12); border: 1px solid rgba(255,90,122,0.35); }
        .btn.small { padding: 8px 12px; font-size: 13px; border-radius: 10px; }
        .empty { color: rgba(255,255,255,0.75); text-align:center; padding: 18px 0; }
    </style>
</head>
<body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
        <div class="wrap">
            <div class="container">
                <h1>PERSONA IMAGES</h1>
                <p class="sub">All persona images for the logged-in tenant.</p>
                <div class="toolbar">
                    <div class="create">
                        <input class="persona-create-name" type="text" maxlength="64" placeholder="New persona name (e.g., Sarah, Coach Mike)" />
                        <button class="btn small persona-create-btn" type="button">Create</button>
                        <a class="btn secondary small" href="/hub/genesis/personas.php">Personas</a>
                    </div>
                </div>
                <div class="meta">
                    <div><strong>user_id:</strong> <?php echo htmlspecialchars((string)($ctx['username'] ?? ''), ENT_QUOTES); ?></div>
                    <div><strong>tenant_id:</strong> <?php echo htmlspecialchars($tenantId, ENT_QUOTES); ?></div>
                    <div><strong>persona_root:</strong> <?php echo htmlspecialchars($root, ENT_QUOTES); ?></div>
                    <div><strong>count:</strong> <?php echo (string)count($items); ?></div>
                </div>

                <?php if (!$items): ?>
                    <div class="empty">No personas found for this tenant.</div>
                    <div class="actions">
                        <a class="btn" href="/hub/genesis/personas.php">Back</a>
                    </div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($items as $it): ?>
                            <?php
                                $pid = (string)($it['persona_id'] ?? '');
                                $pname = (string)($it['persona_name'] ?? '');
                                $createdAt = (string)($it['created_at'] ?? '');
                                $imgUrl = '/hub/genesis/persona-images.php?persona=' . rawurlencode($pid) . '&v=' . time();
                            ?>
                            <div class="card">
                                <div class="img"><img src="<?php echo htmlspecialchars($imgUrl, ENT_QUOTES); ?>" alt="Persona"></div>
                                <div class="body">
                                    <div class="label">persona_name</div>
                                    <div class="value"><?php echo htmlspecialchars($pname, ENT_QUOTES); ?></div>
                                    <div class="label">persona_id</div>
                                    <div class="value"><?php echo htmlspecialchars($pid, ENT_QUOTES); ?></div>
                                    <div class="label">created_at</div>
                                    <div class="value"><?php echo htmlspecialchars($createdAt, ENT_QUOTES); ?></div>
                                    <div class="actions">
                                        <a class="btn secondary" href="/hub/genesis/persona_edit.php?persona_id=<?php echo rawurlencode($pid); ?>">Edit</a>
                                        <button class="btn danger persona-delete" type="button" data-persona-id="<?php echo htmlspecialchars($pid, ENT_QUOTES); ?>" data-persona-name="<?php echo htmlspecialchars($pname !== '' ? $pname : $pid, ENT_QUOTES); ?>">Delete</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
    <script>
      (function () {
        async function createPersona() {
          var input = document.querySelector(".persona-create-name");
          var name = input ? (input.value || "").trim() : "";
          if (!name) { alert("Enter a persona name."); return; }
          try {
            var res = await fetch("/hub/genesis/persona_create.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: "persona_name=" + encodeURIComponent(name),
              credentials: "include"
            });
            var txt = await res.text();
            var j = null;
            try { j = JSON.parse(txt); } catch (e) {}
            if (!j || j.success !== true) {
              alert((j && j.error) ? j.error : ("Create failed: " + (txt || "")));
              return;
            }
            window.location.reload();
          } catch (e) {
            alert("Create error.");
          }
        }

        async function deletePersona(personaId, personaName) {
          if (!personaId) return;
          if (!confirm("Delete persona '" + (personaName || personaId) + "'? This removes its assets.")) return;
          try {
            var res = await fetch("/hub/genesis/persona_delete.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: "persona_id=" + encodeURIComponent(personaId) + "&persona_name=" + encodeURIComponent(personaName || ""),
              credentials: "include"
            });
            var txt = await res.text();
            var j = null;
            try { j = JSON.parse(txt); } catch (e) {}
            if (!j || j.success !== true) {
              alert((j && j.error) ? j.error : ("Delete failed: " + (txt || "")));
              return;
            }
            window.location.reload();
          } catch (e) {
            alert("Delete error.");
          }
        }

        var createBtn = document.querySelector(".persona-create-btn");
        if (createBtn) {
          createBtn.addEventListener("click", function () { createPersona(); });
        }

        var dels = document.querySelectorAll(".persona-delete");
        dels.forEach(function (b) {
          b.addEventListener("click", function () {
            deletePersona(b.getAttribute("data-persona-id") || "", b.getAttribute("data-persona-name") || "");
          });
        });
      })();
    </script>
</body>
</html>
