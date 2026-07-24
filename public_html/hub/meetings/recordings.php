<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/calendar_helpers.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode('/hub/meetings/recordings.php'), true, 302);
    exit;
}
$user = trim($user);

$tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) && trim((string)$_SESSION['mh_tenant_id']) !== ''
    ? trim((string)$_SESSION['mh_tenant_id'])
    : ('user:' . $user);
$tenantSafe = function_exists('mh_tenant_safe') ? (string)mh_tenant_safe($tenantId) : preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
$base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
$base = $base !== '' ? rtrim($base, '/') : '/data';
$tenantRoot = $base . '/tenants/' . $tenantSafe;
$meetingsRoot = $tenantRoot . '/meetings';

mh_apply_tenant_context($tenantId);

function mh_realpath_or_empty(string $p): string
{
    $r = realpath($p);
    return is_string($r) ? $r : '';
}

function mh_path_is_within(string $path, string $root): bool
{
    $rp = mh_realpath_or_empty($path);
    $rr = mh_realpath_or_empty($root);
    if ($rp === '' || $rr === '') {
        return false;
    }
    $rr = rtrim($rr, '/') . '/';
    return strpos($rp . (is_dir($rp) ? '/' : ''), $rr) === 0;
}

function mh_send_file(string $absPath, string $downloadName): void
{
    if (!is_file($absPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }
    $size = filesize($absPath);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (is_int($size) ? $size : 0));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($absPath);
    exit;
}

function mh_csrf_get(): string
{
    $k = $_SESSION['mh_meet_rec_csrf'] ?? '';
    if (!is_string($k) || $k === '') {
        $k = bin2hex(random_bytes(16));
        $_SESSION['mh_meet_rec_csrf'] = $k;
    }
    return $k;
}

function mh_csrf_check(string $posted): bool
{
    $k = $_SESSION['mh_meet_rec_csrf'] ?? '';
    return is_string($k) && $k !== '' && hash_equals($k, $posted);
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
$file = isset($_GET['file']) ? (string)$_GET['file'] : '';

if ($action === 'download') {
    $abs = $meetingsRoot . '/' . ltrim($file, '/');
    if (!mh_path_is_within($abs, $meetingsRoot)) {
        http_response_code(403);
        exit;
    }
    mh_send_file($abs, basename($abs));
}

if ($action === 'delete' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!mh_csrf_check($posted)) {
        http_response_code(403);
        exit;
    }
    $abs = $meetingsRoot . '/' . ltrim($file, '/');
    if (!mh_path_is_within($abs, $meetingsRoot)) {
        http_response_code(403);
        exit;
    }
    if (is_file($abs)) {
        @unlink($abs);
    }
    header('Location: /hub/meetings/recordings.php', true, 302);
    exit;
}

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
$csrf = mh_csrf_get();

$meetings = [];
$roomFilter = isset($_GET['room_id']) ? trim((string)$_GET['room_id']) : '';
if ($roomFilter !== '') {
    $roomFilter = preg_replace('/[^A-Za-z0-9_-]+/', '_', $roomFilter);
}

$searchQ = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$searchResults = [];
if ($searchQ !== '') {
    $db = calendar_get_db();
    if ($db) {
        calendar_ensure_tables($db);
        $searchQ = mb_substr($searchQ, 0, 200);
        try {
            $stmt = $db->prepare("
                SELECT si.meeting_id, si.record_id, si.lang, si.summary_text, m.room_id, m.title, m.scheduled_for_text
                FROM mh_meeting_summary_index si
                JOIN mh_meetings m ON m.id = si.meeting_id
                WHERE MATCH(si.summary_text) AGAINST(:q IN NATURAL LANGUAGE MODE)
                ORDER BY si.updated_at_utc DESC, si.created_at_utc DESC
                LIMIT 50
            ");
            $stmt->execute([':q' => $searchQ]);
            $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            try {
                $stmt = $db->prepare("
                    SELECT si.meeting_id, si.record_id, si.lang, si.summary_text, m.room_id, m.title, m.scheduled_for_text
                    FROM mh_meeting_summary_index si
                    JOIN mh_meetings m ON m.id = si.meeting_id
                    WHERE si.summary_text LIKE :q
                    ORDER BY si.updated_at_utc DESC, si.created_at_utc DESC
                    LIMIT 50
                ");
                $stmt->execute([':q' => '%' . $searchQ . '%']);
                $searchResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                $searchResults = [];
            }
        }
    }
}
if (is_dir($meetingsRoot)) {
    $dirs = scandir($meetingsRoot);
    if (is_array($dirs)) {
        foreach ($dirs as $d) {
            if (!is_string($d) || $d === '.' || $d === '..') continue;
            if ($roomFilter !== '' && $d !== $roomFilter) continue;
            $roomDir = $meetingsRoot . '/' . $d;
            if (!is_dir($roomDir)) continue;
            $recDir = $roomDir . '/recordings';
            $trDir = $roomDir . '/transcripts';
            $sumDir = $roomDir . '/summaries';
            $items = [];
            $indexPath = $recDir . '/index.json';
            if (is_file($indexPath)) {
                $raw = @file_get_contents($indexPath);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                    $items = $decoded['items'];
                }
            }
            $meetings[] = [
                'room_id' => $d,
                'dir' => $roomDir,
                'recordings_dir' => $recDir,
                'transcripts_dir' => $trDir,
                'summaries_dir' => $sumDir,
                'items' => $items,
            ];
        }
    }
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meeting Recordings</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.meeting-recordings main.main-content{max-width:1200px;margin:0 auto;padding:24px}
    .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:18px}
    .title{margin:0 0 14px 0;font-size:22px}
    .muted{color:rgba(255,255,255,.7);font-size:12px}
    .table{width:100%;border-collapse:collapse;margin-top:12px}
    .table th,.table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px;vertical-align:top}
    .table th{color:rgba(255,255,255,.75);font-weight:800}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:transparent;cursor:pointer}
    .btn.danger{border-color:rgba(255,80,80,.35);color:rgba(255,150,150,.95)}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.14);font-size:11px;font-weight:900;color:rgba(255,255,255,.9);text-decoration:none}
    .pill.dim{opacity:.75}
  </style>
</head>
<body class="meeting-recordings">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <h1 class="title">Meeting Recordings</h1>
        <div class="muted">Tenant: <?php echo htmlspecialchars($tenantId, ENT_QUOTES); ?></div>
      </div>
      <div class="row">
        <a class="btn" href="/hub/meetings/">Back</a>
      </div>
    </div>

    <form method="get" action="/hub/meetings/recordings.php" class="row" style="justify-content:space-between;margin-top:12px">
      <input type="hidden" name="room_id" value="<?php echo htmlspecialchars($roomFilter, ENT_QUOTES); ?>">
      <div style="flex:1;min-width:240px">
        <input class="btn" style="width:100%;text-align:left;color:rgba(255,255,255,.92);border-color:rgba(255,255,255,.14)" name="q" value="<?php echo htmlspecialchars($searchQ, ENT_QUOTES); ?>" placeholder="Search meeting summaries">
      </div>
      <div class="row">
        <button class="btn" type="submit">Search</button>
        <a class="btn" href="/hub/meetings/recordings.php<?php echo $roomFilter !== '' ? ('?room_id=' . rawurlencode($roomFilter)) : ''; ?>">Clear</a>
      </div>
    </form>

    <?php if ($searchQ !== ''): ?>
      <div class="muted" style="margin-top:10px"><?php echo htmlspecialchars(count($searchResults) . ' result(s)', ENT_QUOTES); ?></div>
      <?php if (is_array($searchResults) && $searchResults !== []): ?>
        <table class="table" style="margin-top:10px">
          <thead>
            <tr>
              <th>Meeting</th>
              <th>Snippet</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($searchResults as $sr): ?>
            <?php
              $mid = (int)($sr['meeting_id'] ?? 0);
              $rid = (string)($sr['record_id'] ?? '');
              $roomId = (string)($sr['room_id'] ?? '');
              $title = (string)($sr['title'] ?? '');
              $when = (string)($sr['scheduled_for_text'] ?? '');
              $snip = (string)($sr['summary_text'] ?? '');
              $snip = preg_replace('/\\s+/', ' ', $snip);
              if (strlen($snip) > 180) $snip = substr($snip, 0, 180) . '…';
            ?>
            <tr>
              <td>
                <div style="font-weight:900"><?php echo htmlspecialchars($title !== '' ? $title : $roomId, ENT_QUOTES); ?></div>
                <div class="muted"><?php echo htmlspecialchars($when !== '' ? $when : $roomId, ENT_QUOTES); ?></div>
              </td>
              <td class="muted"><?php echo htmlspecialchars($snip, ENT_QUOTES); ?></td>
              <td class="row" style="justify-content:flex-end">
                <a class="btn" href="/hub/meetings/agenda.php?id=<?php echo (int)$mid; ?>">Agenda</a>
                <a class="btn" href="/hub/meetings/vote.php?id=<?php echo (int)$mid; ?>">Votes</a>
                <a class="btn" href="/hub/meetings/recordings.php?room_id=<?php echo rawurlencode($roomId); ?>">Artifacts</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($meetings === []): ?>
      <div class="muted" style="margin-top:12px">No recordings ingested yet.</div>
    <?php else: ?>
      <?php foreach ($meetings as $m): ?>
        <?php $roomId = (string)($m['room_id'] ?? ''); $items = is_array($m['items'] ?? null) ? (array)$m['items'] : []; ?>
        <h2 style="margin:18px 0 8px 0;font-size:15px">Room: <?php echo htmlspecialchars($roomId, ENT_QUOTES); ?></h2>
        <table class="table">
          <thead>
            <tr>
              <th>Recording</th>
              <th>Artifacts</th>
              <th>Downloaded</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($items === []): ?>
            <tr><td colspan="4" class="muted">No recordings for this room yet.</td></tr>
          <?php else: foreach ($items as $it): ?>
            <?php
              $rid = isset($it['record_id']) ? (string)$it['record_id'] : '';
              $local = isset($it['local_file']) ? (string)$it['local_file'] : '';
              $when = isset($it['downloaded_at_utc']) ? (string)$it['downloaded_at_utc'] : '';
              $fileRel = rawurlencode($roomId . '/recordings/' . $local);
              $dl = '/hub/meetings/recordings.php?action=download&file=' . $fileRel;
              $del = '/hub/meetings/recordings.php?action=delete&file=' . $fileRel;

              $trPills = [];
              $sumPills = [];
              $vttPills = [];
              $prefix = preg_replace('/[^A-Za-z0-9._-]+/', '_', $rid);
              $trDir = $meetingsRoot . '/' . $roomId . '/transcripts';
              $sumDir = $meetingsRoot . '/' . $roomId . '/summaries';
              if ($prefix !== '' && is_dir($trDir)) {
                  $tfiles = scandir($trDir);
                  if (is_array($tfiles)) {
                      foreach ($tfiles as $tf) {
                          if (!is_string($tf) || $tf === '.' || $tf === '..') continue;
                          if (strpos($tf, $prefix . '_') !== 0) continue;
                          $ext = strtolower(pathinfo($tf, PATHINFO_EXTENSION));
                          $rel = rawurlencode($roomId . '/transcripts/' . $tf);
                          if ($ext === 'vtt') {
                              $vttPills[] = ['label' => $tf, 'href' => '/hub/meetings/recordings.php?action=download&file=' . $rel];
                          } elseif ($ext === 'txt' || $ext === 'json') {
                              $trPills[] = ['label' => $tf, 'href' => '/hub/meetings/recordings.php?action=download&file=' . $rel];
                          }
                      }
                  }
              }
              if ($prefix !== '' && is_dir($sumDir)) {
                  $sfiles = scandir($sumDir);
                  if (is_array($sfiles)) {
                      foreach ($sfiles as $sf) {
                          if (!is_string($sf) || $sf === '.' || $sf === '..') continue;
                          if (strpos($sf, $prefix . '_') !== 0) continue;
                          $rel = rawurlencode($roomId . '/summaries/' . $sf);
                          $sumPills[] = ['label' => $sf, 'href' => '/hub/meetings/recordings.php?action=download&file=' . $rel];
                      }
                  }
              }
            ?>
            <tr>
              <td>
                <div style="font-weight:900"><?php echo htmlspecialchars($rid !== '' ? $rid : $local, ENT_QUOTES); ?></div>
                <div class="muted"><?php echo htmlspecialchars($local, ENT_QUOTES); ?></div>
              </td>
              <td>
                <?php if ($vttPills !== []): ?>
                  <div class="muted" style="margin-bottom:6px">Subtitles</div>
                  <div class="row">
                    <?php foreach ($vttPills as $p): ?>
                      <a class="pill dim" href="<?php echo htmlspecialchars($p['href'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($p['label'], ENT_QUOTES); ?></a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($trPills !== []): ?>
                  <div class="muted" style="margin:10px 0 6px">Transcript</div>
                  <div class="row">
                    <?php foreach ($trPills as $p): ?>
                      <a class="pill" href="<?php echo htmlspecialchars($p['href'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($p['label'], ENT_QUOTES); ?></a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($sumPills !== []): ?>
                  <div class="muted" style="margin:10px 0 6px">Summary</div>
                  <div class="row">
                    <?php foreach ($sumPills as $p): ?>
                      <a class="pill" href="<?php echo htmlspecialchars($p['href'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($p['label'], ENT_QUOTES); ?></a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($vttPills === [] && $trPills === [] && $sumPills === []): ?>
                  <div class="muted">No artifacts yet.</div>
                <?php endif; ?>
              </td>
              <td class="muted"><?php echo htmlspecialchars($when !== '' ? $when : '—', ENT_QUOTES); ?></td>
              <td class="row" style="justify-content:flex-end">
                <a class="btn" href="<?php echo htmlspecialchars($dl, ENT_QUOTES); ?>">Download</a>
                <form method="post" action="<?php echo htmlspecialchars($del, ENT_QUOTES); ?>" style="margin:0">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                  <button class="btn danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
