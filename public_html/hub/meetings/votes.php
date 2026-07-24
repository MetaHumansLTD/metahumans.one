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

$db = calendar_get_db();
if (!$db) {
    http_response_code(500);
    echo 'Calendar database unavailable';
    exit;
}
calendar_ensure_tables($db);

$stmt = $db->prepare("
    SELECT
      v.id AS vote_id,
      v.meeting_id,
      v.title AS vote_title,
      v.kind,
      v.status,
      v.weight_basis,
      v.created_at_utc,
      v.closed_at_utc,
      m.room_id,
      m.title AS meeting_title,
      m.scheduled_for_text,
      s.name AS series_name
    FROM mh_meeting_votes v
    JOIN mh_meetings m ON m.id = v.meeting_id
    LEFT JOIN mh_meeting_series s ON s.id = m.series_id
    LEFT JOIN mh_meeting_attendees a ON a.meeting_id = m.id AND a.username = :u_join
    WHERE (m.created_by_user = :u_owner OR a.id IS NOT NULL)
    ORDER BY v.id DESC
    LIMIT 300
");
$stmt->execute([':u_join' => $user, ':u_owner' => $user]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows)) $rows = [];

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Votes</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <style>
    body.hub-votes-list main.main-content{max-width:1200px;margin:0 auto;padding:24px}
    .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:18px}
    .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .title{margin:0 0 10px 0;font-size:22px}
    .muted{color:rgba(255,255,255,.7);font-size:12px}
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:rgba(0,0,0,.12);cursor:pointer}
    table{width:100%;border-collapse:collapse;margin-top:12px}
    th,td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px;vertical-align:top}
    th{color:rgba(255,255,255,.75);font-weight:800}
  </style>
</head>
<body class="hub-votes-list">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="card">
    <div class="row">
      <div>
        <h1 class="title">Votes</h1>
        <div class="muted">All votes for meetings you created</div>
      </div>
      <div class="row">
        <a class="btn" href="/hub/meetings/">Meetings</a>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Vote</th>
          <th>Meeting</th>
          <th>Series</th>
          <th>Status</th>
          <th>Weight</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="6" class="muted">No votes yet.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <?php
          $voteId = (int)($r['vote_id'] ?? 0);
          $meetingId = (int)($r['meeting_id'] ?? 0);
          $voteTitle = (string)($r['vote_title'] ?? '');
          $meetingTitle = (string)($r['meeting_title'] ?? '');
          $roomId = (string)($r['room_id'] ?? '');
          $seriesName = (string)($r['series_name'] ?? '');
          $status = (string)($r['status'] ?? '');
          $basis = (string)($r['weight_basis'] ?? '');
          $when = (string)($r['scheduled_for_text'] ?? '');
        ?>
        <tr>
          <td>
            <div style="font-weight:900"><?php echo htmlspecialchars($voteTitle !== '' ? $voteTitle : ('Vote #' . $voteId), ENT_QUOTES); ?></div>
            <div class="muted"><?php echo htmlspecialchars((string)($r['created_at_utc'] ?? ''), ENT_QUOTES); ?></div>
          </td>
          <td>
            <div style="font-weight:900"><?php echo htmlspecialchars($meetingTitle !== '' ? $meetingTitle : $roomId, ENT_QUOTES); ?></div>
            <div class="muted"><?php echo htmlspecialchars($when !== '' ? $when : ('Room: ' . $roomId), ENT_QUOTES); ?></div>
          </td>
          <td class="muted"><?php echo htmlspecialchars($seriesName !== '' ? $seriesName : '—', ENT_QUOTES); ?></td>
          <td><?php echo htmlspecialchars($status !== '' ? $status : '—', ENT_QUOTES); ?></td>
          <td class="muted"><?php echo htmlspecialchars($basis !== '' ? $basis : '—', ENT_QUOTES); ?></td>
          <td class="row" style="justify-content:flex-end">
            <a class="btn" href="/hub/meetings/vote.php?id=<?php echo (int)$meetingId; ?>">View</a>
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
