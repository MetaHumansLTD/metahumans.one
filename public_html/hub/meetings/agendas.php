<?php
declare(strict_types=1);

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
    header('Location: /auth/login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/hub/meetings/'), true, 302);
    exit;
}
$user = trim($user);
$tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) && trim((string)$_SESSION['mh_tenant_id']) !== ''
    ? trim((string)$_SESSION['mh_tenant_id'])
    : ('user:' . $user);
try {
    $okCtx = mh_apply_tenant_context($tenantId);
    if ($okCtx !== true) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Tenant context unavailable';
        exit;
    }
} catch (Throwable) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Tenant context unavailable';
    exit;
}

if (!isset($_SESSION['mh_agendas_csrf']) || !is_string($_SESSION['mh_agendas_csrf']) || $_SESSION['mh_agendas_csrf'] === '') {
    $_SESSION['mh_agendas_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['mh_agendas_csrf'];

$db = calendar_get_db();
if (!$db) {
    http_response_code(500);
    echo 'Calendar database unavailable';
    exit;
}
calendar_ensure_tables($db);

function mh_agendas_tenant_safe(string $tenantId): string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') $tenantId = 'user:unknown';
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if ($safe !== '') return $safe;
    }
    return preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
}

function mh_agendas_meeting_root(string $tenantSafe, string $roomId): string
{
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    $roomId = preg_replace('/[^A-Za-z0-9_-]+/', '_', $roomId);
    return $base . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
}

$msg = '';
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $err = 'Invalid request';
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        if ($action === 'delete_agenda') {
            $meetingId = isset($_POST['meeting_id']) ? (int)$_POST['meeting_id'] : 0;
            if ($meetingId > 0) {
                $stmt = $db->prepare("SELECT room_id FROM mh_meetings WHERE id = ? AND created_by_user = ? LIMIT 1");
                $stmt->execute([$meetingId, $user]);
                $roomId = (string)$stmt->fetchColumn();
                if ($roomId !== '') {
                    try {
                        $stmt = $db->prepare("DELETE FROM mh_meeting_agendas WHERE meeting_id = ? LIMIT 1");
                        $stmt->execute([$meetingId]);
                        $tenantSafe = mh_agendas_tenant_safe($tenantId);
                        $root = mh_agendas_meeting_root($tenantSafe, $roomId);
                        @unlink($root . '/agenda/agenda.json');
                        @unlink($root . '/minutes/minutes.md');
                        $msg = 'Agenda deleted';
                    } catch (Throwable $e) {
                        $err = $e->getMessage();
                    }
                }
            }
        }
    }
}

$rows = [];
try {
    $stmt = $db->prepare("
        SELECT
          m.id AS meeting_id,
          m.room_id,
          m.title,
          m.scheduled_for_text,
          m.series_id,
          s.name AS series_name,
          a.updated_at_utc,
          a.created_at_utc
        FROM mh_meeting_agendas a
        JOIN mh_meetings m ON m.id = a.meeting_id
        LEFT JOIN mh_meeting_series s ON s.id = m.series_id
        WHERE m.created_by_user = ?
        ORDER BY COALESCE(a.updated_at_utc, a.created_at_utc) DESC
        LIMIT 300
    ");
    $stmt->execute([$user]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) $rows = [];
} catch (Throwable $e) {
    $rows = [];
    $err = $err !== '' ? $err : $e->getMessage();
}

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Agendas</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.hub-agendas main.main-content{max-width:1200px;margin:0 auto;padding:24px}
    .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:18px}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .title{margin:0 0 10px 0;font-size:22px}
    .muted{color:rgba(255,255,255,.7);font-size:12px}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:rgba(0,0,0,.12);cursor:pointer}
    .btn.danger{border-color:rgba(255,80,80,.35);color:rgba(255,180,180,.95)}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px;vertical-align:top}
    th{color:rgba(255,255,255,.75);font-weight:800}
  </style>
</head>
<body class="hub-agendas">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="card">
    <div class="row">
      <div>
        <h1 class="title">Agendas</h1>
        <div class="muted">All agendas for meetings you created</div>
      </div>
      <div class="row">
        <a class="btn" href="/hub/meetings/">Meetings</a>
      </div>
    </div>

    <?php if ($msg !== ''): ?><div class="muted" style="margin-top:10px;color:rgba(180,255,210,.95)"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></div><?php endif; ?>
    <?php if ($err !== ''): ?><div class="muted" style="margin-top:10px;color:rgba(255,180,180,.95)"><?php echo htmlspecialchars($err, ENT_QUOTES); ?></div><?php endif; ?>

    <table>
      <thead>
        <tr>
          <th>Meeting</th>
          <th>Series</th>
          <th>When</th>
          <th>Updated</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="5" class="muted">No agendas yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <?php
          $mid = (int)($r['meeting_id'] ?? 0);
          $roomId = (string)($r['room_id'] ?? '');
          $title = (string)($r['title'] ?? '');
          $seriesName = (string)($r['series_name'] ?? '');
          $when = (string)($r['scheduled_for_text'] ?? '');
          $updated = (string)($r['updated_at_utc'] ?? '');
          if ($updated === '') $updated = (string)($r['created_at_utc'] ?? '');
        ?>
        <tr>
          <td>
            <div style="font-weight:900"><?php echo htmlspecialchars($title !== '' ? $title : $roomId, ENT_QUOTES); ?></div>
            <div class="muted">Room: <?php echo htmlspecialchars($roomId, ENT_QUOTES); ?></div>
          </td>
          <td class="muted"><?php echo htmlspecialchars($seriesName !== '' ? $seriesName : '—', ENT_QUOTES); ?></td>
          <td class="muted"><?php echo htmlspecialchars($when !== '' ? $when : '—', ENT_QUOTES); ?></td>
          <td class="muted"><?php echo htmlspecialchars($updated !== '' ? $updated : '—', ENT_QUOTES); ?></td>
          <td class="row" style="justify-content:flex-end">
            <a class="btn" href="/hub/meetings/agenda.php?id=<?php echo (int)$mid; ?>">Edit</a>
            <form method="post" style="margin:0" onsubmit="return confirm('Delete this agenda?');">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
              <input type="hidden" name="action" value="delete_agenda">
              <input type="hidden" name="meeting_id" value="<?php echo (int)$mid; ?>">
              <button class="btn danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</main>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
