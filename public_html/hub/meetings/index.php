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
    header('Location: /auth/login.php?redirect=' . rawurlencode('/hub/meetings/'), true, 302);
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

if (!isset($_SESSION['mh_meetings_csrf']) || !is_string($_SESSION['mh_meetings_csrf']) || $_SESSION['mh_meetings_csrf'] === '') {
    $_SESSION['mh_meetings_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['mh_meetings_csrf'];

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : 'meetings';
if ($tab !== 'series') {
    $tab = 'meetings';
}
$view = isset($_GET['view']) ? trim((string)$_GET['view']) : 'upcoming';
if (!in_array($view, ['upcoming', 'past', 'all'], true)) {
    $view = 'upcoming';
}
$seriesFilter = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;
if ($seriesFilter < 1) {
    $seriesFilter = 0;
}

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meetings</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
  <script src="/templates/widgets/notices/popup-notice.js"></script>
  <style>
    body.hub-meetings main.main-content{max-width:1200px;margin:0 auto;padding:24px}
    .m-card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:18px}
    .m-row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between}
    .m-title{margin:0 0 14px 0;font-size:22px}
    .m-table{width:100%;border-collapse:collapse;margin-top:12px}
    .m-table th,.m-table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:13px}
    .m-table th{color:rgba(255,255,255,.75);font-weight:800}
    .m-actions a,.m-actions button{display:inline-flex;align-items:center;justify-content:center;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:rgba(0,0,0,.12);cursor:pointer}
    .m-muted{color:rgba(255,255,255,.7);font-size:12px}
    .m-tabs{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
    .m-tab{padding:8px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.14);text-decoration:none;color:rgba(255,255,255,.9);font-weight:900;font-size:12px;background:rgba(0,0,0,.10)}
    .m-tab.active{border-color:rgba(0,212,255,.35);background:rgba(0,212,255,.12);color:#d7fbff}
    .m-filter{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:12px}
    .m-filter select{border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.22);color:rgba(255,255,255,.92);padding:8px 10px}
    .m-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.70);z-index:99999}
    .m-modal.open{display:flex}
    .m-modal-card{width:100%;max-width:980px;border-radius:18px;border:1px solid rgba(255,255,255,.14);background:linear-gradient(180deg, rgba(8,14,26,.98), rgba(5,8,16,.98));padding:18px 18px 16px;box-shadow:0 18px 60px rgba(0,0,0,.55)}
    .m-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,.10)}
    .m-modal-title{font-size:14px;font-weight:950;letter-spacing:.4px}
    .m-close{border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:rgba(255,255,255,.92);padding:8px 12px;cursor:pointer;text-decoration:none}
    .m-form{display:flex;flex-direction:column;gap:12px;margin-top:14px}
    .m-input,.m-text{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:rgba(255,255,255,.92)}
    .m-row2{display:flex;gap:12px;flex-wrap:wrap}
    .m-row2>div{flex:1;min-width:240px}
    .m-btn-primary{border-radius:12px;border:1px solid rgba(0,212,255,.35);background:rgba(0,212,255,.16);color:#d7fbff;padding:10px 12px;font-weight:950;cursor:pointer}
    .m-btn-danger{border-radius:12px;border:1px solid rgba(255,80,80,.35);background:rgba(255,80,80,.12);color:rgba(255,180,180,.95);padding:10px 12px;font-weight:950;cursor:pointer}
  </style>
</head>
<body class="hub-meetings">
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
  <div class="m-card">
    <div class="m-row">
      <div>
        <h1 class="m-title">Meetings</h1>
        <div class="m-muted">Upcoming and past meetings created by <?php echo htmlspecialchars($user, ENT_QUOTES); ?></div>
        <div class="m-tabs">
          <a class="m-tab <?php echo $tab === 'meetings' ? 'active' : ''; ?>" href="/hub/meetings/?tab=meetings&view=<?php echo htmlspecialchars($view, ENT_QUOTES); ?>">Meetings</a>
          <a class="m-tab <?php echo $tab === 'series' ? 'active' : ''; ?>" href="/hub/meetings/?tab=series">Series</a>
        </div>
      </div>
      <div class="m-actions">
        <button type="button" onclick="mhOpenSchedule()">Schedule</button>
        <a href="/hub/calendar/">Calendar</a>
        <a href="/hub/meetings/agendas.php">Agendas</a>
        <a href="/hub/meetings/votes.php">Votes</a>
        <a href="/hub/meetings/recordings.php">Recordings</a>
      </div>
    </div>
    <?php
      $dbError = '';
      $db = null;
      $rows = [];
      $series = [];
      try {
          mh_apply_tenant_context($tenantId);
          $db = calendar_get_db();
          if ($db) {
              calendar_ensure_tables($db);
              $s = $db->prepare("SELECT id, name, created_by_user, created_at_utc FROM mh_meeting_series ORDER BY id DESC LIMIT 200");
              $s->execute();
              $series = $s->fetchAll(PDO::FETCH_ASSOC);

              if ($tab === 'meetings') {
                  $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
                  // Filter: creator OR attendee
                  $where = "(m.created_by_user = :u_where OR att.id IS NOT NULL)";
                  $params = [':u_where' => $user, ':u_join' => $user];
                  if ($seriesFilter > 0) {
                      $where .= " AND m.series_id = :sid";
                      $params[':sid'] = $seriesFilter;
                  }
                  if ($view === 'upcoming') {
                      $where .= " AND m.status <> 'canceled' AND (m.scheduled_for_utc IS NULL OR m.scheduled_for_utc >= :now)";
                      $params[':now'] = $nowUtc;
                  } elseif ($view === 'past') {
                      $where .= " AND (m.status = 'canceled' OR (m.scheduled_for_utc IS NOT NULL AND m.scheduled_for_utc < :now))";
                      $params[':now'] = $nowUtc;
                  }
                  $stmt = $db->prepare("
                    SELECT DISTINCT
                      m.id,
                      m.room_id,
                      m.title,
                      m.invite_url,
                      m.presenter_join_url,
                      m.participant_join_url,
                      m.scheduled_for_utc,
                      m.scheduled_for_text,
                      m.created_at_utc,
                      m.token_charge_status,
                      m.token_charge_amount,
                      m.token_charged_at_utc,
                      m.token_charge_error,
                      m.series_id,
                      m.status,
                      m.canceled_at_utc,
                      m.canceled_reason,
                      m.created_by_user,
                      s.name AS series_name
                    FROM mh_meetings m
                    LEFT JOIN mh_meeting_series s ON s.id = m.series_id
                    LEFT JOIN mh_meeting_attendees att ON att.meeting_id = m.id AND att.username = :u_join
                    WHERE {$where}
                    ORDER BY COALESCE(m.scheduled_for_utc, m.created_at_utc) DESC
                    LIMIT 300
                  ");
                  $stmt->execute($params);
                  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
              }
          }
      } catch (Throwable $e) {
          $dbError = $e->getMessage();
          $rows = [];
          $series = [];
      }
    ?>
    <?php if ($dbError !== ''): ?>
      <div class="m-muted" style="margin-top:12px;color:rgba(255,180,180,.95)"><?php echo htmlspecialchars($dbError, ENT_QUOTES); ?></div>
    <?php endif; ?>
    <?php if ($tab === 'series'): ?>
      <div class="m-filter">
        <div class="m-muted">Series help you group meetings for agendas, carry-over, and votes.</div>
      </div>
      <div style="margin-top:12px" class="m-actions">
        <button type="button" onclick="mhOpenSeries()">Create series</button>
      </div>
      <div class="m-muted" id="seriesListErr" style="display:none;margin-top:10px;color:rgba(255,180,180,.95)"></div>
      <table class="m-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Owner</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!is_array($series) || $series === []): ?>
          <tr><td colspan="4" class="m-muted">No series yet.</td></tr>
        <?php else: foreach ($series as $s): ?>
          <?php
            $sid = (int)($s['id'] ?? 0);
            $name = (string)($s['name'] ?? '');
            $owner = (string)($s['created_by_user'] ?? '');
            $created = (string)($s['created_at_utc'] ?? '');
          ?>
          <tr>
            <td><?php echo htmlspecialchars($name, ENT_QUOTES); ?></td>
            <td class="m-muted"><?php echo htmlspecialchars($owner !== '' ? $owner : '—', ENT_QUOTES); ?></td>
            <td class="m-muted"><?php echo htmlspecialchars($created !== '' ? $created : '—', ENT_QUOTES); ?></td>
            <td class="m-actions">
              <a href="/hub/meetings/?tab=meetings&view=<?php echo htmlspecialchars($view, ENT_QUOTES); ?>&series_id=<?php echo (int)$sid; ?>">View meetings</a>
              <?php if ($owner !== '' && $owner === $user): ?>
                <button type="button" class="m-btn-danger" onclick="mhDeleteSeries(<?php echo (int)$sid; ?>,'<?php echo htmlspecialchars($name, ENT_QUOTES); ?>')">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="m-filter">
        <div>
          <select onchange="location.href=this.value">
            <option value="/hub/meetings/?tab=meetings&view=upcoming<?php echo $seriesFilter > 0 ? ('&series_id=' . (int)$seriesFilter) : ''; ?>" <?php echo $view === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
            <option value="/hub/meetings/?tab=meetings&view=past<?php echo $seriesFilter > 0 ? ('&series_id=' . (int)$seriesFilter) : ''; ?>" <?php echo $view === 'past' ? 'selected' : ''; ?>>Past</option>
            <option value="/hub/meetings/?tab=meetings&view=all<?php echo $seriesFilter > 0 ? ('&series_id=' . (int)$seriesFilter) : ''; ?>" <?php echo $view === 'all' ? 'selected' : ''; ?>>All</option>
          </select>
        </div>
        <div>
          <select onchange="location.href=this.value">
            <option value="/hub/meetings/?tab=meetings&view=<?php echo htmlspecialchars($view, ENT_QUOTES); ?>" <?php echo $seriesFilter < 1 ? 'selected' : ''; ?>>All series</option>
            <?php foreach ($series as $s): ?>
              <?php $sid = (int)($s['id'] ?? 0); $name = (string)($s['name'] ?? ''); $owner = (string)($s['created_by_user'] ?? ''); ?>
              <option value="/hub/meetings/?tab=meetings&view=<?php echo htmlspecialchars($view, ENT_QUOTES); ?>&series_id=<?php echo (int)$sid; ?>" <?php echo $seriesFilter === $sid ? 'selected' : ''; ?>><?php echo htmlspecialchars($name, ENT_QUOTES); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="m-muted">Times stored in UTC; your browser renders in local time.</div>
      </div>
      <table class="m-table">
        <thead>
          <tr>
            <th>Title</th>
            <th>When</th>
            <th>Status</th>
            <th>Billing</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!is_array($rows) || $rows === []): ?>
          <tr><td colspan="5" class="m-muted">No meetings yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <?php
            $id = (int)($r['id'] ?? 0);
            $roomId = isset($r['room_id']) ? (string)$r['room_id'] : '';
            $title = isset($r['title']) ? (string)$r['title'] : $roomId;
            $when = isset($r['scheduled_for_text']) ? (string)$r['scheduled_for_text'] : '';
            $scheduledUtc = isset($r['scheduled_for_utc']) ? (string)$r['scheduled_for_utc'] : '';
            $status = isset($r['status']) ? (string)$r['status'] : 'scheduled';
            $billing = isset($r['token_charge_status']) ? (string)$r['token_charge_status'] : 'none';
            $amount = (int)($r['token_charge_amount'] ?? 0);
            $err = isset($r['token_charge_error']) ? (string)$r['token_charge_error'] : '';
            $seriesName = isset($r['series_name']) ? (string)$r['series_name'] : '';
            $presenterJoin = isset($r['presenter_join_url']) ? (string)$r['presenter_join_url'] : '';
            $participantJoin = isset($r['participant_join_url']) ? (string)$r['participant_join_url'] : '';
            $inviteUrl = isset($r['invite_url']) ? (string)$r['invite_url'] : '';
            $participantJoin = str_replace('role=participant', 'role=viewer', $participantJoin);
            $inviteUrl = str_replace('role=participant', 'role=viewer', $inviteUrl);
            $isOwner = isset($r['created_by_user']) && trim((string)$r['created_by_user']) === $user;
            if ($isOwner) {
                $join = $presenterJoin !== '' ? $presenterJoin : ('/meet.php?room_id=' . rawurlencode($roomId) . '&role=presenter');
            } else {
                $join = $participantJoin !== '' ? $participantJoin : ($inviteUrl !== '' ? $inviteUrl : ('/meet.php?room_id=' . rawurlencode($roomId) . '&role=viewer'));
            }
            $isCanceled = $status === 'canceled';
          ?>
          <tr>
            <td>
              <div style="font-weight:900"><?php echo htmlspecialchars($title, ENT_QUOTES); ?></div>
              <div class="m-muted">
                Room: <?php echo htmlspecialchars($roomId, ENT_QUOTES); ?>
                <?php echo $seriesName !== '' ? (' · Series: ' . htmlspecialchars($seriesName, ENT_QUOTES)) : ''; ?>
                <?php echo $scheduledUtc !== '' ? (' · UTC: ' . htmlspecialchars($scheduledUtc, ENT_QUOTES)) : ''; ?>
              </div>
              <?php if ($isCanceled): ?>
                <?php $reason = isset($r['canceled_reason']) ? (string)$r['canceled_reason'] : ''; $cat = isset($r['canceled_at_utc']) ? (string)$r['canceled_at_utc'] : ''; ?>
                <div class="m-muted" style="color:rgba(255,180,180,.95)"><?php echo htmlspecialchars('Canceled' . ($cat !== '' ? (' · ' . $cat) : '') . ($reason !== '' ? (' · ' . $reason) : ''), ENT_QUOTES); ?></div>
              <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($when !== '' ? $when : '—', ENT_QUOTES); ?></td>
            <td><?php echo htmlspecialchars($status, ENT_QUOTES); ?></td>
            <td>
              <div><?php echo htmlspecialchars($billing, ENT_QUOTES); ?><?php echo $amount > 0 ? (' · ' . (int)$amount) : ''; ?></div>
              <?php if ($err !== ''): ?><div class="m-muted" style="color:rgba(255,160,160,.95)"><?php echo htmlspecialchars($err, ENT_QUOTES); ?></div><?php endif; ?>
            </td>
            <td class="m-actions" style="gap:8px;display:flex;justify-content:flex-end;flex-wrap:wrap">
              <a href="<?php echo htmlspecialchars($isCanceled ? ($inviteUrl !== '' ? $inviteUrl : '#') : $join, ENT_QUOTES); ?>" <?php echo $isCanceled ? 'style="opacity:.6;pointer-events:none"' : ''; ?>>Join</a>
              <button type="button" <?php echo $isCanceled ? 'disabled style="opacity:.6;cursor:not-allowed"' : ''; ?> onclick="mhOpenShare('<?php echo htmlspecialchars($roomId, ENT_QUOTES); ?>','<?php echo htmlspecialchars($when, ENT_QUOTES); ?>')">Share</button>
              <a href="/hub/meetings/agenda.php?id=<?php echo (int)$id; ?>">Agenda</a>
              <a href="/hub/meetings/vote.php?id=<?php echo (int)$id; ?>">Votes</a>
              <a href="/hub/meetings/recordings.php?room_id=<?php echo rawurlencode($roomId); ?>">Artifacts</a>
              <button type="button" onclick="mhOpenReschedule(<?php echo (int)$id; ?>,'<?php echo htmlspecialchars($title, ENT_QUOTES); ?>','<?php echo htmlspecialchars($scheduledUtc, ENT_QUOTES); ?>')">Reschedule</button>
              <button type="button" class="m-btn-danger" <?php echo $isCanceled ? 'disabled style="opacity:.6;cursor:not-allowed"' : ''; ?> onclick="mhOpenCancel(<?php echo (int)$id; ?>,'<?php echo htmlspecialchars($title, ENT_QUOTES); ?>')">Cancel</button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</main>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
<div class="m-modal" id="mSchedule">
  <div class="m-modal-card">
    <div class="m-modal-header">
      <div>
        <div class="m-modal-title">Schedule meeting</div>
        <div class="m-muted">Create a meeting and generate invite links.</div>
      </div>
      <button class="m-close" type="button" onclick="mhClose('mSchedule')">Close</button>
    </div>
    <div class="m-form">
      <div><input class="m-input" id="schedTitle" placeholder="Meeting name (e.g. Dev Sync)"></div>
      <div class="m-row2">
        <div><input class="m-input" id="schedDate" type="date"></div>
        <div><input class="m-input" id="schedTime" type="time"></div>
      </div>
      <div>
        <select class="m-input" id="schedSeries">
          <option value="">No series</option>
          <?php foreach ($series as $s): ?>
            <?php $sid = (int)($s['id'] ?? 0); $name = (string)($s['name'] ?? ''); ?>
            <option value="<?php echo (int)$sid; ?>" <?php echo $seriesFilter === $sid ? 'selected' : ''; ?>><?php echo htmlspecialchars($name, ENT_QUOTES); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="m-muted" id="schedErr" style="display:none;color:rgba(255,180,180,.95)"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
        <button class="m-btn-primary" type="button" id="schedCreate" onclick="mhCreateMeeting()">Create</button>
      </div>
    </div>
  </div>
</div>

<div class="m-modal" id="mShare" onclick="if(event.target===this)mhClose('mShare')">
  <div class="m-modal-card" style="max-width:560px;padding:0;overflow:hidden">
    <div class="m-modal-header" style="padding:12px 12px 10px">
      <div class="m-modal-title">Invite participants</div>
      <div style="display:flex;gap:8px;align-items:center">
        <a id="shareAgendaLink" href="/hub/meetings/agendas.php" style="display:none;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:var(--primary-color,#00d4ff);font-weight:900;font-size:12px;background:rgba(0,0,0,.12)">Agenda</a>
        <button class="m-close" type="button" onclick="mhClose('mShare')">Close</button>
      </div>
    </div>
    <iframe id="shareFrame" title="Meeting share" src="about:blank" style="width:100%;height:560px;border:0;background:transparent"></iframe>
  </div>
</div>

<div class="m-modal" id="mResched">
  <div class="m-modal-card" style="max-width:520px">
    <div class="m-modal-header">
      <div>
        <div class="m-modal-title">Reschedule</div>
        <div class="m-muted" id="reschedTitle"></div>
      </div>
      <button class="m-close" type="button" onclick="mhClose('mResched')">Close</button>
    </div>
    <div class="m-form">
      <div><input class="m-input" id="reschedWhen" type="datetime-local"></div>
      <div class="m-muted" id="reschedErr" style="display:none;color:rgba(255,180,180,.95)"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
        <button class="m-btn-primary" type="button" onclick="mhDoReschedule()">Save</button>
      </div>
    </div>
  </div>
</div>

<div class="m-modal" id="mCancel">
  <div class="m-modal-card" style="max-width:520px">
    <div class="m-modal-header">
      <div>
        <div class="m-modal-title">Cancel meeting</div>
        <div class="m-muted" id="cancelTitle"></div>
      </div>
      <button class="m-close" type="button" onclick="mhClose('mCancel')">Close</button>
    </div>
    <div class="m-form">
      <div><input class="m-input" id="cancelReason" placeholder="Reason (optional)"></div>
      <div class="m-muted" id="cancelErr" style="display:none;color:rgba(255,180,180,.95)"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
        <button class="m-btn-danger" type="button" onclick="mhDoCancel()">Confirm cancel</button>
      </div>
    </div>
  </div>
</div>

<div class="m-modal" id="mSeries">
  <div class="m-modal-card" style="max-width:520px">
    <div class="m-modal-header">
      <div>
        <div class="m-modal-title">Create series</div>
        <div class="m-muted">Group recurring meetings for agendas and votes.</div>
      </div>
      <button class="m-close" type="button" onclick="mhClose('mSeries')">Close</button>
    </div>
    <div class="m-form">
      <div><input class="m-input" id="seriesName" placeholder="Series name (e.g. Board Meeting)"></div>
      <div class="m-muted" id="seriesErr" style="display:none;color:rgba(255,180,180,.95)"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
        <button class="m-btn-primary" type="button" onclick="mhCreateSeries()">Create</button>
      </div>
    </div>
  </div>
</div>

<script>
const MH_MEET_CSRF = <?php echo json_encode($csrf); ?>;
let mhMeetingId = 0;
let mhCancelId = 0;
let mhLastMeetingId = 0;
let mhNoticeReady = false;

function mhNotice(type, message){
  try{
    if(!mhNoticeReady){
      if(window.PopupNotice && !window.globalPopupNotice){
        window.globalPopupNotice = new PopupNotice({position:'top-right', theme:'modern', duration:4200});
      }
      mhNoticeReady = true;
    }
    if(window.globalPopupNotice){
      const fn = window.globalPopupNotice[type] || window.globalPopupNotice.info;
      fn.call(window.globalPopupNotice, String(message||''));
      return;
    }
  }catch(e){}
  alert(String(message||''));
}

function mhOpen(id){ const el=document.getElementById(id); if(el) el.classList.add('open'); }
function mhClose(id){
  const el=document.getElementById(id);
  if(el) el.classList.remove('open');
  if(id==='mShare'){
    const f=document.getElementById('shareFrame'); if(f) f.src='about:blank';
    const a=document.getElementById('shareAgendaLink'); if(a){ a.style.display='none'; a.href='/hub/meetings/agendas.php'; }
    mhLastMeetingId = 0;
  }
}

function mhOpenSchedule(){
  const now=new Date();
  const y=now.getFullYear();
  const m=String(now.getMonth()+1).padStart(2,'0');
  const d=String(now.getDate()).padStart(2,'0');
  const hh=String(now.getHours()).padStart(2,'0');
  const mm=String(now.getMinutes()).padStart(2,'0');
  const dt=document.getElementById('schedDate'); if(dt && !dt.value) dt.value=`${y}-${m}-${d}`;
  const tm=document.getElementById('schedTime'); if(tm && !tm.value) tm.value=`${hh}:${mm}`;
  const err=document.getElementById('schedErr'); if(err){ err.style.display='none'; err.textContent=''; }
  mhOpen('mSchedule');
}

function mhOpenSeries(){
  const name = prompt('Series name');
  if(name === null) return;
  const v = String(name||'').trim();
  if(!v) return;
  mhCreateSeries(v);
}

function mhOpenShare(roomId, scheduledText){
  const parts=(scheduledText||'').trim().split(/\s+/);
  const date=parts[0]||'';
  const time=parts[1]||'';
  let src='/hub/meet/meeting_share.php?room_id='+encodeURIComponent(roomId)+'&embed=1';
  if(date) src+='&date='+encodeURIComponent(date);
  if(time) src+='&time='+encodeURIComponent(time);
  const f=document.getElementById('shareFrame'); if(f) f.src=src;
  const a=document.getElementById('shareAgendaLink');
  if(a){
    if(mhLastMeetingId > 0){
      a.href='/hub/meetings/agenda.php?id='+encodeURIComponent(String(mhLastMeetingId));
      a.style.display='inline-flex';
    }else{
      a.style.display='none';
      a.href='/hub/meetings/agendas.php';
    }
  }
  mhOpen('mShare');
}

document.addEventListener('keydown', function(e){
  if(e.key !== 'Escape'){ return; }
  const modal = document.getElementById('mShare');
  if(modal && modal.classList.contains('open')){
    e.preventDefault();
    mhClose('mShare');
  }
});

function mhYmdHisFromLocal(v){
  if(!v) return '';
  const d=new Date(v);
  if(isNaN(d.getTime())) return '';
  const y=d.getUTCFullYear();
  const m=String(d.getUTCMonth()+1).padStart(2,'0');
  const dd=String(d.getUTCDate()).padStart(2,'0');
  const hh=String(d.getUTCHours()).padStart(2,'0');
  const mm=String(d.getUTCMinutes()).padStart(2,'0');
  const ss=String(d.getUTCSeconds()).padStart(2,'0');
  return `${y}-${m}-${dd} ${hh}:${mm}:${ss}`;
}

async function mhCreateMeeting(){
  const title=(document.getElementById('schedTitle')?.value||'').trim();
  const date=(document.getElementById('schedDate')?.value||'').trim();
  const time=(document.getElementById('schedTime')?.value||'').trim();
  const seriesId=(document.getElementById('schedSeries')?.value||'').trim();
  const err=document.getElementById('schedErr');
  if(err){ err.style.display='none'; err.textContent=''; }
  if(!title){
    if(err){ err.style.display='block'; err.textContent='Meeting name is required.'; }
    return;
  }
  const fd=new FormData();
  fd.append('title', title);
  if(date) fd.append('date', date);
  if(time) fd.append('time', time);
  if(date && time){
    const localIso = `${date}T${time}`;
    const utc = mhYmdHisFromLocal(localIso);
    if(utc) fd.append('scheduled_utc', utc);
  }
  if(seriesId) fd.append('series_id', seriesId);
  const btn=document.getElementById('schedCreate');
  try{
    if(btn){ btn.disabled=true; btn.textContent='Creating…'; }
    const res=await fetch('/hub/meet/schedule-api.php',{method:'POST',body:fd,credentials:'include'});
    const ct=(res.headers.get('content-type')||'').toLowerCase();
    const raw=await res.text();
    if(!ct.includes('application/json')){
      throw new Error(raw.slice(0,120));
    }
    const data=JSON.parse(raw);
    if(!res.ok || !data || data.ok!==true){
      let msg=data && data.error ? data.error : ('Request failed ('+res.status+')');
      if(data && data.error==='insufficient_tokens'){
        msg='Insufficient tokens. Balance: '+String(data.balance||data.balance_tokens||0)+' · Cost: '+String(data.cost||data.cost_tokens||0);
      }
      if(err){ err.style.display='block'; err.textContent=msg; }
      return;
    }
    mhLastMeetingId = Number(data.meeting_id||0) || 0;
    mhClose('mSchedule');
    mhOpenShare(data.room_id||'', data.scheduled_text||'');
    setTimeout(()=>{ location.reload(); }, 450);
  }catch(e){
    if(err){ err.style.display='block'; err.textContent='Request failed'; }
  }finally{
    if(btn){ btn.disabled=false; btn.textContent='Create'; }
  }
}

async function mhPostJson(url, fd){
  const res = await fetch(url, {method:'POST', body:fd, credentials:'include'});
  const ct = (res.headers.get('content-type')||'').toLowerCase();
  const raw = await res.text();
  if(ct.includes('application/json')){
    const data = raw ? JSON.parse(raw) : null;
    return {res, data, raw};
  }
  return {res, data:null, raw};
}

function mhOpenReschedule(id,title,scheduledUtc){
  mhMeetingId=id;
  const t=document.getElementById('reschedTitle'); if(t) t.textContent=title||'';
  const err=document.getElementById('reschedErr'); if(err){ err.style.display='none'; err.textContent=''; }
  const input=document.getElementById('reschedWhen');
  if(input){
    input.value='';
    if(scheduledUtc){
      const d=new Date(scheduledUtc.replace(' ','T')+'Z');
      if(!isNaN(d.getTime())){
        const y=d.getFullYear();
        const m=String(d.getMonth()+1).padStart(2,'0');
        const dd=String(d.getDate()).padStart(2,'0');
        const hh=String(d.getHours()).padStart(2,'0');
        const mm=String(d.getMinutes()).padStart(2,'0');
        input.value=`${y}-${m}-${dd}T${hh}:${mm}`;
      }
    }
  }
  mhOpen('mResched');
}

async function mhDoReschedule(){
  const v=(document.getElementById('reschedWhen')?.value||'').trim();
  const err=document.getElementById('reschedErr');
  if(err){ err.style.display='none'; err.textContent=''; }
  const utc=mhYmdHisFromLocal(v);
  if(!utc){
    if(err){ err.style.display='block'; err.textContent='Invalid time.'; }
    return;
  }
  const localText = v.includes('T') ? v.replace('T',' ') : v;
  const fd=new FormData();
  fd.append('csrf', MH_MEET_CSRF);
  fd.append('action', 'reschedule');
  fd.append('id', String(mhMeetingId||0));
  fd.append('scheduled_utc', utc);
  fd.append('scheduled_text', localText || utc);
  try{
    const res=await fetch('/hub/meetings/api.php',{method:'POST',body:fd,credentials:'include'});
    const data=await res.json().catch(()=>null);
    if(!res.ok || !data || data.ok!==true){
      if(err){ err.style.display='block'; err.textContent=(data&&data.error)?data.error:'Request failed'; }
      return;
    }
    mhClose('mResched');
    location.reload();
  }catch(e){
    if(err){ err.style.display='block'; err.textContent='Request failed'; }
  }
}

function mhOpenCancel(id,title){
  mhCancelId=id;
  const t=document.getElementById('cancelTitle'); if(t) t.textContent=title||'';
  const err=document.getElementById('cancelErr'); if(err){ err.style.display='none'; err.textContent=''; }
  const r=document.getElementById('cancelReason'); if(r) r.value='';
  mhOpen('mCancel');
}

async function mhDoCancel(){
  const reason=(document.getElementById('cancelReason')?.value||'').trim();
  const err=document.getElementById('cancelErr');
  if(err){ err.style.display='none'; err.textContent=''; }
  const fd=new FormData();
  fd.append('csrf', MH_MEET_CSRF);
  fd.append('action', 'cancel');
  fd.append('id', String(mhCancelId||0));
  fd.append('reason', reason);
  try{
    const res=await fetch('/hub/meetings/api.php',{method:'POST',body:fd,credentials:'include'});
    const data=await res.json().catch(()=>null);
    if(!res.ok || !data || data.ok!==true){
      if(err){ err.style.display='block'; err.textContent=(data&&data.error)?data.error:'Request failed'; }
      return;
    }
    mhClose('mCancel');
    location.reload();
  }catch(e){
    if(err){ err.style.display='block'; err.textContent='Request failed'; }
  }
}

async function mhCreateSeries(nameOverride){
  const name = (typeof nameOverride === 'string' ? nameOverride : (document.getElementById('seriesName')?.value||'')).trim();
  const err=document.getElementById('seriesErr');
  if(err){ err.style.display='none'; err.textContent=''; }
  if(!name){
    if(err){ err.style.display='block'; err.textContent='Series name is required.'; }
    return;
  }
  const fd=new FormData();
  fd.append('csrf', MH_MEET_CSRF);
  fd.append('action','series_create');
  fd.append('name', name);
  try{
    const {res, data, raw} = await mhPostJson('/hub/meetings/api.php', fd);
    if(!res.ok || !data || data.ok!==true){
      const msg = data && data.error ? data.error : ('Request failed ('+res.status+')');
      const extra = (!data && raw) ? (' · '+raw.slice(0,140)) : '';
      mhNotice('error', msg+extra);
      return;
    }
    mhClose('mSeries');
    mhNotice('success', 'Series created');
    location.reload();
  }catch(e){
    mhNotice('error', 'Request failed');
  }
}

async function mhDeleteSeries(id, name){
  if(!id) return;
  if(!confirm('Delete series \"'+(name||'')+'\"? Meetings in this series will become unassigned.')) return;
  const fd=new FormData();
  fd.append('csrf', MH_MEET_CSRF);
  fd.append('action','series_delete');
  fd.append('id', String(id));
  try{
    const {res, data, raw} = await mhPostJson('/hub/meetings/api.php', fd);
    if(!res.ok || !data || data.ok!==true){
      const msg = data && data.error ? data.error : ('Request failed ('+res.status+')');
      const extra = (!data && raw) ? (' · '+raw.slice(0,140)) : '';
      mhNotice('error', msg+extra);
      return;
    }
    mhNotice('success', 'Series deleted');
    location.reload();
  }catch(e){
    mhNotice('error', 'Request failed');
  }
}

function mhApplyPrefillFromUrl(){
  try{
    const u = new URL(window.location.href);
    const p = u.searchParams;
    const t = (p.get('prefill_title')||'').trim();
    const d = (p.get('prefill_date')||'').trim();
    const tm = (p.get('prefill_time')||'').trim();
    const sid = (p.get('prefill_series_id')||'').trim();
    if(!t && !d && !tm && !sid) return;
    const titleEl = document.getElementById('schedTitle'); if(titleEl && t) titleEl.value = t;
    const dateEl = document.getElementById('schedDate'); if(dateEl && d) dateEl.value = d;
    const timeEl = document.getElementById('schedTime'); if(timeEl && tm) timeEl.value = tm;
    const seriesEl = document.getElementById('schedSeries'); if(seriesEl && sid) seriesEl.value = sid;
    p.delete('prefill_title');
    p.delete('prefill_date');
    p.delete('prefill_time');
    p.delete('prefill_series_id');
    window.history.replaceState({}, document.title, u.pathname + (p.toString() ? ('?' + p.toString()) : '') + u.hash);
    mhOpenSchedule();
  }catch(e){}
}

mhApplyPrefillFromUrl();

async function mhPollMeetingReminders(){
  try{
    const minutes = Number(localStorage.getItem('mh_meeting_reminder_minutes')||'120') || 120;
    const res = await fetch('/hub/meetings/reminders_api.php?minutes='+encodeURIComponent(String(minutes)), {credentials:'include'});
    const data = await res.json().catch(()=>null);
    if(!res.ok || !data || data.ok!==true) return;
    const meetings = Array.isArray(data.meetings) ? data.meetings : [];
    for(const m of meetings){
      const id = Number(m.id||0) || 0;
      if(id < 1) continue;
      const key = 'mh_reminder_shown_'+String(id);
      if(sessionStorage.getItem(key) === '1') continue;
      const title = String(m.title||m.room_id||('Meeting #'+id));
      const when = String(m.scheduled_for_text||m.scheduled_for_utc||'');
      mhNotice('info', 'Upcoming meeting in '+String(minutes)+' min window: '+title+' · '+when);
      sessionStorage.setItem(key,'1');
      try{
        const ctx = new (window.AudioContext||window.webkitAudioContext)();
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        o.type='sine'; o.frequency.value=880;
        g.gain.value=0.04;
        o.connect(g); g.connect(ctx.destination);
        o.start();
        setTimeout(()=>{ try{o.stop();}catch(e){} try{ctx.close();}catch(e){} }, 220);
      }catch(e){}
      break;
    }
  }catch(e){}
}

setInterval(mhPollMeetingReminders, 60000);
setTimeout(mhPollMeetingReminders, 1200);
</script>
</body>
</html>
