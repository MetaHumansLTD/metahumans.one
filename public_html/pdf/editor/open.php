<?php
require_once __DIR__ . '/lib.php';
mh_pdf_editor_require_auth();

$userId = (string)($_SESSION['mh_auth_user'] ?? '');
$id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if ($id === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
    http_response_code(400);
    exit('Invalid id');
}

$row = mh_pdf_editor_get_record($id, $userId);
if (!$row || (string)$row['owner_id'] !== $userId) {
    http_response_code(404);
    exit('Not found');
}

$token = mh_pdf_editor_refresh_token($id, $userId);

$officeBase = 'https://office.metahumans.one';
$wopiSrc = 'https://metahumans.one/pdf/wopi/files/' . rawurlencode($id);

function mh_pdf_editor_fetch_discovery(string $officeBase): string {
    $cacheDir = mh_pdf_editor_base_dir();
    $cacheFile = $cacheDir . '/discovery.xml';
    $ttl = 3600;
    if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $ttl) {
        $c = (string)file_get_contents($cacheFile);
        if ($c !== '') {
            return $c;
        }
    }
    $url = rtrim($officeBase, '/') . '/hosting/discovery';
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $xml = @file_get_contents($url, false, $ctx);
    if (!is_string($xml) || $xml === '') {
        throw new RuntimeException('discovery_fetch_failed');
    }
    mh_pdf_editor_ensure_dirs();
    @file_put_contents($cacheFile, $xml, LOCK_EX);
    return $xml;
}

function mh_pdf_editor_discovery_urlsrc_for_ext(string $xml, string $ext, array $names): string {
    $doc = new DOMDocument();
    $ok = @$doc->loadXML($xml, LIBXML_NONET);
    if (!$ok) {
        throw new RuntimeException('discovery_parse_failed');
    }
    $xpath = new DOMXPath($doc);
    foreach ($names as $name) {
        if (!is_string($name) || $name === '') {
            continue;
        }
        $nodes = $xpath->query('//action[@ext="' . $ext . '" and @name="' . $name . '"]/@urlsrc');
        if ($nodes && $nodes->length > 0) {
            $urlsrc = (string)$nodes->item(0)->nodeValue;
            if ($urlsrc === '') {
                continue;
            }
            return $urlsrc;
        }
    }
    throw new RuntimeException('discovery_action_not_found');
}

try {
    $discoveryXml = mh_pdf_editor_fetch_discovery($officeBase);
    $urlsrc = mh_pdf_editor_discovery_urlsrc_for_ext($discoveryXml, 'pdf', ['edit', 'view_comment', 'view']);
    if (strpos($urlsrc, '<WOPISrc>') !== false) {
        $editorUrl = str_replace('<WOPISrc>', rawurlencode($wopiSrc), $urlsrc);
    } else {
        if (substr($urlsrc, -1) === '?') {
            $sep = '';
        } else {
            $sep = (strpos($urlsrc, '?') === false) ? '?' : '&';
        }
        $editorUrl = $urlsrc . $sep . 'WOPISrc=' . rawurlencode($wopiSrc);
    }
    $sep = (strpos($editorUrl, '?') === false) ? '?' : '&';
    $editorUrl = $editorUrl . $sep . 'access_token=' . rawurlencode((string)$token['token']);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Editor is not available');
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>MetaHumans PDF Editor</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
      main.main-content { width: 100%; display: flex; }
      .mh-app-shell { padding: 16px; box-sizing: border-box; width: 100%; display: flex; flex-direction: column; gap: 12px; flex: 1; }
      .mh-topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
      .mh-title { margin: 0; font-size: 1rem; font-weight: 700; color: rgba(226, 232, 240, 0.98); }
      .mh-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
      .mh-btn { display: inline-block; padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(148, 163, 184, 0.24); background: rgba(2, 6, 23, 0.75); color: rgba(226, 232, 240, 0.98); text-decoration: none; cursor: pointer; }
      .mh-btn:hover { background: rgba(2, 6, 23, 0.9); }
      .mh-frame-wrap { position: relative; flex: 1; min-height: 640px; }
      .mh-app-frame { width: 100%; height: 100%; border: 0; border-radius: 14px; overflow: hidden; background: rgba(2, 6, 23, 0.35); }
      .mh-loading { position: absolute; inset: 16px; border-radius: 14px; background: rgba(2, 6, 23, 0.55); border: 1px solid rgba(148, 163, 184, 0.18); display: flex; align-items: center; justify-content: center; color: rgba(226, 232, 240, 0.92); font-weight: 600; letter-spacing: 0.2px; pointer-events: none; }
      @media (max-width: 640px) { .mh-app-shell { padding: 10px; } .mh-loading { inset: 10px; } .mh-title { font-size: 0.95rem; } }
    </style>
  </head>
  <body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
      <div class="mh-app-shell">
        <div class="mh-topbar">
          <h1 class="mh-title"><?php echo htmlspecialchars((string)$row['filename']); ?></h1>
          <div class="mh-actions">
            <a class="mh-btn" href="/pdf/editor/">Close</a>
          </div>
        </div>
        <div class="mh-frame-wrap">
          <div class="mh-loading" id="mhLoading">Loading editor…</div>
          <iframe class="mh-app-frame" id="mhAppFrame" title="MetaHumans PDF Editor" src="<?php echo htmlspecialchars($editorUrl); ?>" allow="clipboard-read; clipboard-write"></iframe>
        </div>
      </div>
    </main>
    <script>
      (function () {
        var frame = document.getElementById("mhAppFrame");
        var loading = document.getElementById("mhLoading");
        var officeOrigin = <?php echo json_encode($officeBase, JSON_UNESCAPED_SLASHES); ?>;
        var hidesSent = false;
        function sendHides() {
          if (!frame || !frame.contentWindow) return;
          var now = Date.now();
          var messages = [
            { MessageId: "Hide_Menu_Item", SendTime: now, Values: { id: "help" } },
            { MessageId: "Hide_Menu_Item", SendTime: now, Values: { id: "about" } }
          ];
          for (var i = 0; i < messages.length; i++) {
            try {
              frame.contentWindow.postMessage(messages[i], officeOrigin);
            } catch (e) {}
          }
          hidesSent = true;
        }
        if (frame && loading) {
          frame.addEventListener("load", function () {
            loading.style.display = "none";
            setTimeout(sendHides, 300);
            setTimeout(sendHides, 1200);
          });
        }

        window.addEventListener("message", function (event) {
          if (!event || event.origin !== officeOrigin) return;
          var data = event.data;
          if (typeof data === "string") {
            try { data = JSON.parse(data); } catch (e) { return; }
          }
          if (!data || typeof data !== "object") return;
          var id = data.MessageId || data.messageId || data.id;
          if (!id) return;
          if (id === "Document_Loaded" || id === "App_LoadingStatus") {
            if (!hidesSent) {
              sendHides();
              setTimeout(sendHides, 800);
            }
          }
        });
      })();
    </script>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
  </body>
</html>
