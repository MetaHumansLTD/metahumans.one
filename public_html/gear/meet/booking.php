<?php
$root = dirname(__DIR__, 2);
require_once $root . '/.cue/cue.php';
require_once __DIR__ . '/meet_helpers.php';
require_once __DIR__ . '/calendar_helpers.php';
require_once $root . '/auth/tenant_provisioning.php';
require_once $root . '/auth/auth_functions.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

function mh_require_login(): void
{
    $u = $_SESSION['mh_auth_user'] ?? null;
    if (!is_string($u) || trim($u) === '') {
        $redir = '/auth/login.php';
        $qs = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        if ($qs !== '') {
            $redir .= '?redirect=' . rawurlencode($qs);
        }
        header('Location: ' . $redir, true, 302);
        exit;
    }
    $u = trim($u);
    if ($u !== '' && function_exists('mh_refresh_session_token_balance')) {
        mh_refresh_session_token_balance($u, 0);
    }
}

function mh_current_username(): string
{
    $u = $_SESSION['mh_auth_user'] ?? null;
    return is_string($u) ? trim($u) : '';
}

function mh_get_user_tokens(string $username): int
{
    $tokBal = null;
    if (function_exists('mh_get_token_balance')) {
        $bal = mh_get_token_balance($username);
        if (is_int($bal)) {
            $tokBal = $bal;
        }
    }
    $fallback = (int)($_SESSION['tokens'] ?? 0);
    if (is_int($tokBal)) {
        $final = max($tokBal, $fallback);
        $_SESSION['tokens'] = $final;
        return $final;
    }
    return $fallback;
}

function mh_meeting_cost_tokens(): int
{
    if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_get_service_pricing')) {
        return 50;
    }
    try {
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdo);
        $row = mh_tokenomics_get_service_pricing($pdo, 'meet:meeting', 50);
        $tpu = (int)($row['tokens_per_unit'] ?? 50);
        return max(1, $tpu);
    } catch (Throwable) {
        return 50;
    }
}

function mh_templates_path(): string
{
    if (function_exists('getTemplatesPath')) {
        $p = (string)getTemplatesPath();
        if ($p !== '') {
            return $p;
        }
    }
    return dirname(__DIR__, 2) . '/templates';
}

function mh_page_start(string $title): void
{
    $templatesPath = mh_templates_path();
    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';

    if (is_file($templatesPath . '/global-ui/includes/complete-head.php')) {
        include_once $templatesPath . '/global-ui/includes/complete-head.php';
    }

    echo '<style>'
        . 'html,body{background-color:var(--background-color,#0a0a1a) !important;color:var(--text-color,#ffffff) !important;}'
        . 'main.main-content{max-width:1200px;margin:0 auto;padding:18px 20px}'
        . '.mh-center{display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 260px)}'
        . '.mh-card{width:100%;max-width:980px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.35);backdrop-filter:blur(6px);padding:22px;box-shadow:0 10px 30px rgba(0,0,0,.35)}'
        . '.mh-card.narrow{max-width:620px}'
        . '.mh-title{margin:0 0 14px 0;font-size:22px}'
        . '.mh-subtitle{margin:16px 0 8px;font-size:13px;color:rgba(255,255,255,.75);font-weight:700}'
        . '.mh-label{display:block;margin:12px 0 6px;font-weight:800}'
        . '.mh-input,.mh-select,.mh-textarea{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:rgba(255,255,255,.92)}'
        . '.mh-textarea{min-height:84px;resize:vertical}'
        . '.mh-row{display:flex;gap:12px;flex-wrap:wrap}'
        . '.mh-row>div{flex:1;min-width:240px}'
        . '.mh-btn{margin-top:16px;width:100%;padding:12px;border:0;border-radius:10px;background:var(--primary-color,#00d4ff);color:#00131f;font-weight:900;cursor:pointer}'
        . '.mh-hint{margin-top:10px;font-size:12px;color:rgba(255,255,255,.7)}'
        . '.mh-links{display:flex;justify-content:space-between;gap:12px;margin-top:14px;flex-wrap:wrap}'
        . '.mh-links a{color:var(--primary-color,#00d4ff);text-decoration:none;font-size:12px}.mh-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.70);overflow:auto;z-index:99999}.mh-modal:target{display:flex}.mh-modal-card{width:100%;max-width:980px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;border-radius:18px;border:1px solid rgba(255,255,255,.14);background:linear-gradient(180deg, rgba(8,14,26,.98), rgba(5,8,16,.98));padding:18px 18px 16px;box-shadow:0 18px 60px rgba(0,0,0,.55)}.mh-modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,.10);flex-wrap:wrap}.mh-modal-title{font-size:14px;font-weight:950;letter-spacing:.4px}.mh-modal-body{margin-top:14px;flex:1;min-height:0;overflow:auto}.mh-modal-frame{width:100%;height:100%;min-height:min(520px,calc(100vh - 220px));max-height:calc(100vh - 220px);border:0;border-radius:14px;background:transparent}.mh-close{border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:rgba(255,255,255,.92);padding:8px 12px;cursor:pointer;text-decoration:none}.mh-share{display:flex;gap:16px;flex-wrap:wrap;margin-top:16px}.mh-qr{width:220px;height:220px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.20)}.mh-share-actions{flex:1;min-width:280px;display:flex;flex-direction:column;gap:12px}.mh-share-row{display:flex;gap:10px;flex-wrap:wrap}.mh-share-btn{border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:rgba(255,255,255,.92);padding:10px 12px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.mh-share-btn.primary{background:rgba(0,212,255,.16);border-color:rgba(0,212,255,.35);color:#d7fbff}.mh-share-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.mh-share-card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);padding:12px}.mh-share-card .mh-hint{margin:0 0 8px 0}.mh-share-card .mh-row{gap:10px}.mh-links a[href*="/gear/meet/settings.php"],.mh-links a[href*="/auth/logout.php"],a[href*="/gear/meet/settings.php"],a[href*="/auth/logout.php"]{display:none!important}'
        . '.mh-modal-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.mh-qr{object-fit:cover}@media (max-width:720px){.mh-modal-header{align-items:stretch}.mh-modal-actions{width:100%;justify-content:stretch}.mh-modal-actions .mh-close{flex:1 1 140px;text-align:center}}'
        . '</style>'
        . '</head><body>';

    if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) {
        include_once $templatesPath . '/global-ui/includes/complete-body-start.php';
    }

    echo '<main class="main-content">';
}

function mh_page_end(): void
{
    $templatesPath = mh_templates_path();
    echo '</main>';
    if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) {
        include_once $templatesPath . '/global-ui/includes/complete-body-end.php';
    }
    echo '</body></html>';
}

function mh_current_user_name(): string
{
    $u = $_SESSION['mh_auth_user'] ?? null;
    if (is_array($u)) {
        $name = trim((string)($u['name'] ?? $u['display_name'] ?? $u['username'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

function mh_public_invite_link(string $roomId, string $role): string
{
    $base = 'https://metahumans.one/booking.php';
    $q = http_build_query(['room_id' => $roomId, 'role' => $role]);
    return $base . '?' . $q;
}

function mh_normalize_room(string $raw): array
{
    $title = trim($raw);
    if ($title === '') {
        return ['room_id' => '', 'title' => ''];
    }

    $ascii = preg_replace('/[^ -~]/', '', $title);
    $room = preg_replace('/\s+/', '_', $ascii);
    $room = preg_replace('/[^A-Za-z0-9_-]/', '_', $room);
    $room = preg_replace('/_+/', '_', $room);
    $room = trim($room, "_- ");

    if ($room === '') {
        $room = 'mh_' . substr(hash('sha256', $title), 0, 12);
    }

    if (strlen($room) > 64) {
        $room = substr($room, 0, 48) . '_' . substr(hash('sha256', $room), 0, 12);
    }

    return ['room_id' => $room, 'title' => $title];
}

function mh_render_booking_form(): void
{
    mh_require_login();
    $defaultName = htmlspecialchars(mh_current_user_name(), ENT_QUOTES);
    $username = mh_current_username();
    $tokens = mh_get_user_tokens($username);
    $meetingCost = mh_meeting_cost_tokens();
    $todayYmd = date('Y-m-d');
    $todayDmy = date('d/m/Y');
    $nowTime = date('H:i');
    $prefillTime = isset($_GET['time']) ? trim((string)$_GET['time']) : '';
    if ($prefillTime === '') {
        $prefillTime = $nowTime;
    }
    $prefillYmd = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
    if ($prefillYmd === '') {
        $prefillYmd = $todayYmd;
    }
    $prefillDmy = $todayDmy;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $prefillYmd)) {
        $parts = explode('-', $prefillYmd);
        if (count($parts) === 3) {
            $prefillDmy = $parts[2] . '/' . $parts[1] . '/' . $parts[0];
        }
    }
    mh_page_start('Book a MetaHumans Meeting');

    echo '<div class="mh-center"><div class="mh-card">'
        . '<h1 class="mh-title">Create a Meeting</h1>'
        . '<form method="get" action="">'
        . '<label class="mh-label" for="room">Meeting Name</label><input class="mh-input" id="room" name="room_id" placeholder="e.g. Pieter_Rubeus" required>'
        . '<div class="mh-row">'
        . '<div><label class="mh-label" for="date_display">Date</label>'
        . '<div class="mh-row" style="gap:10px;align-items:center;flex-wrap:nowrap">'
        . '<input class="mh-input" id="date_display" name="date_display" value="' . htmlspecialchars($prefillDmy, ENT_QUOTES) . '" placeholder="dd/mm/yyyy" inputmode="numeric" autocomplete="off" style="margin-top:0">'
        . '<input id="date" name="date" type="date" value="' . htmlspecialchars($prefillYmd, ENT_QUOTES) . '" style="position:fixed;top:0;left:0;width:40px;height:40px;opacity:0;border:0;padding:0;margin:0">'
        . '<button class="mh-share-btn" type="button" style="width:auto;white-space:nowrap" onclick="mhOpenDatePicker()">Select</button>'
        . '</div></div>'
        . '<div><label class="mh-label" for="time">Time</label><input class="mh-input" id="time" name="time" type="time" value="' . htmlspecialchars($prefillTime, ENT_QUOTES) . '"></div>'
        . '</div>'
        . '<div class="mh-row">'
        . '<div><label class="mh-label" for="role">Your Role</label><select class="mh-select" id="role" name="role" required><option value="presenter">Presenter</option><option value="viewer">Viewer</option></select></div>'
        . '<div><label class="mh-label" for="name">Your Name</label><input class="mh-input" id="name" name="name" placeholder="e.g. Pieter Rubeus" value="' . $defaultName . '" required></div>'
        . '</div>'
        . '<button class="mh-btn" type="submit">Create</button>'
        . '<div class="mh-hint">Cost: <b>' . (int)$meetingCost . ' tokens</b> per meeting (charged only if meeting runs for 5 minutes with participants). Your balance: <b>' . number_format($tokens) . '</b>.</div>'
        . '</form></div></div>'
        . '<script>'
        . 'function mhOpenDatePicker(){var d=document.getElementById("date");if(!d)return;try{d.focus({preventScroll:true});}catch(e){}try{if(d.showPicker){d.showPicker();}else{d.click();}}catch(e){try{d.click();}catch(e2){}}}'
        . 'function mhPad2(n){n=String(n);return n.length===1?("0"+n):n;}'
        . 'function mhSyncDateFromHidden(){var d=document.getElementById("date");var t=document.getElementById("date_display");if(!d||!t)return;var v=d.value||"";if(!v.match(/^\\d{4}-\\d{2}-\\d{2}$/))return;var p=v.split("-");t.value=p[2]+"/"+p[1]+"/"+p[0];}'
        . 'function mhSyncHiddenFromText(){var d=document.getElementById("date");var t=document.getElementById("date_display");if(!d||!t)return;var v=(t.value||"").trim();var m=v.match(/^(\\d{1,2})\\/(\\d{1,2})\\/(\\d{4})$/);if(!m)return;var dd=mhPad2(m[1]);var mm=mhPad2(m[2]);var yy=m[3];d.value=yy+"-"+mm+"-"+dd;}'
        . 'document.addEventListener("DOMContentLoaded",function(){var d=document.getElementById("date");var t=document.getElementById("date_display");if(d){d.addEventListener("change",mhSyncDateFromHidden);} if(t){t.addEventListener("blur",mhSyncHiddenFromText);} var f=t? t.closest("form"):null; if(f){f.addEventListener("submit",function(){mhSyncHiddenFromText();});}});'
        . '</script>';

    mh_page_end();
}

function mh_render_invite_join_form(string $roomId, string $roomTitle, string $role): void
{
    $safeRoomTitle = htmlspecialchars($roomTitle, ENT_QUOTES);
    $safeRoomId = htmlspecialchars($roomId, ENT_QUOTES);
    $role = $role === 'presenter' ? 'presenter' : 'viewer';

    mh_page_start('Join Meeting');

    echo '<div class="mh-center"><div class="mh-card narrow">'
        . '<h1 class="mh-title">Join Meeting</h1>'
        . '<div class="mh-hint">Room: <b>' . $safeRoomTitle . '</b></div>'
        . (($roomTitle !== $roomId) ? ('<div class="mh-hint">Room ID: <b>' . $safeRoomId . '</b></div>') : '')
        . '<form method="get" action="">'
        . '<input type="hidden" name="room_id" value="' . $safeRoomId . '">'
        . '<input type="hidden" name="role" value="' . htmlspecialchars($role, ENT_QUOTES) . '">'
        . '<input type="hidden" name="direct" value="1">'
        . '<label class="mh-label" for="name">Your Name</label><input class="mh-input" id="name" name="name" placeholder="e.g. Alex" required>'
        . '<button class="mh-btn" type="submit">Join</button>'
        . '</form></div></div>';

    mh_page_end();
}

function mh_render_join_result(string $roomId, string $roomTitle, string $hostJoinUrl, string $presenterJoinLink, string $participantJoinLink, string $scheduledText): void
{
    $safeHostJoin = htmlspecialchars($hostJoinUrl, ENT_QUOTES);
    $safePresenterJoin = htmlspecialchars($presenterJoinLink, ENT_QUOTES);
    $safeParticipantJoin = htmlspecialchars($participantJoinLink, ENT_QUOTES);
    $safeRoomTitle = htmlspecialchars($roomTitle, ENT_QUOTES);
    $safeRoomId = htmlspecialchars($roomId, ENT_QUOTES);
    $safeScheduled = htmlspecialchars($scheduledText, ENT_QUOTES);
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . rawurlencode($participantJoinLink);

    mh_page_start('Meeting Created');

    echo '<div class="mh-center"><div class="mh-card">'
        . '<h1 class="mh-title">Meeting Ready</h1>'
        . '<div class="mh-subtitle">Room</div><div style="font-weight:900">' . $safeRoomTitle . '</div>'
        . (($roomTitle !== $roomId) ? ('<div class="mh-hint">Room ID: <b>' . $safeRoomId . '</b></div>') : '')
        . '<div class="mh-subtitle">Presenter Join Link</div><input class="mh-input" readonly value="' . $safeHostJoin . '" style="font-size:14px">'
        . '<button class="mh-btn" type="button" onclick="location.href=\'' . $safeHostJoin . '\'">Join Now</button>'
        . '<a class="mh-btn" href="/hub/meetings/agenda.php?room_id=' . rawurlencode($roomId) . '" style="margin-top:12px;display:block;text-align:center">Agenda</a>'
        . '<a class="mh-btn" href="#mhInviteModal" id="mhInviteModalOpen" style="margin-top:12px;display:block;text-align:center">Invite participants</a>'
        . '<div class="mh-modal" id="mhInviteModal" role="dialog" aria-modal="true">'
        . '<div class="mh-modal-card">'
        . '<div class="mh-modal-header">'
        . '<div>'
        . '<div class="mh-modal-title">Invite participants</div>'
        . '<div class="mh-hint" style="margin:6px 0 0 0">Room: <b>' . $safeRoomTitle . '</b>' . ($scheduledText !== '' ? (' · Scheduled: <b>' . $safeScheduled . '</b>') : '') . '</div>'
        . '</div>'
        . '<div class="mh-modal-actions">'
        . '<a class="mh-close" href="/hub/meetings/agenda.php?room_id=' . rawurlencode($roomId) . '">Agenda</a>'
        . '<a class="mh-close" href="#">Close</a>'
        . '</div>'
        . '</div>'
        . '<div class="mh-modal-body">'
        . '<div class="mh-share" style="align-items:flex-start">'
        . '<img class="mh-qr" src="' . htmlspecialchars($qrUrl, ENT_QUOTES) . '" alt="Participant QR code">'
        . '<div class="mh-share-actions" style="min-width:0">'
        . '<div class="mh-share-card">'
        . '<div class="mh-hint" style="margin:0 0 8px 0">Participant join link</div>'
        . '<input class="mh-input" id="mhParticipantJoinLink" readonly value="' . $safeParticipantJoin . '" style="font-size:14px">'
        . '<div class="mh-share-row" style="margin-top:10px">'
        . '<button class="mh-share-btn primary" type="button" onclick="mhInviteOpenLink()">Open meeting</button>'
        . '<button class="mh-share-btn" type="button" onclick="mhInviteCopyLink()">Copy link</button>'
        . '<button class="mh-share-btn" type="button" onclick="mhInviteShare()">Share...</button>'
        . '</div>'
        . '</div>'
        . '<div class="mh-hint">Share this participant link with attendees. It opens the viewer join path and does not use presenter billing.</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>'
        . '</div>';

    echo '<div class="mh-links" style="margin-top:14px"><a href="/hub/meet/">Create another meeting</a></div>'
        . '<script>'
        . '(function(){'
        . 'var modalId="mhInviteModal";'
        . 'var participantLink=' . json_encode($participantJoinLink, JSON_UNESCAPED_SLASHES) . ';'
        . 'var shareText=' . json_encode('Join this MetaHumans meeting' . ($scheduledText !== '' ? (' on ' . $scheduledText) : '') . ':', JSON_UNESCAPED_SLASHES) . ';'
        . 'function mhCloseInviteModal(){'
        . 'if(window.location.hash==="#"+modalId){'
        . 'if(window.history&&window.history.replaceState){window.history.replaceState(null,document.title,window.location.pathname+window.location.search);}else{window.location.hash="";}'
        . '}'
        . '}'
        . 'window.mhInviteOpenLink=function(){window.location.href=participantLink;};'
        . 'window.mhInviteCopyLink=function(){var input=document.getElementById("mhParticipantJoinLink");if(!input){return;}var fullText=shareText+" "+participantLink;if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(fullText).catch(function(){var old=input.value;input.value=fullText;input.select();input.setSelectionRange(0,99999);document.execCommand("copy");input.value=old;});}else{var old=input.value;input.value=fullText;input.select();input.setSelectionRange(0,99999);document.execCommand("copy");input.value=old;}};'
        . 'window.mhInviteShare=function(){if(navigator.share){navigator.share({title:"MetaHumans Meeting",text:shareText,url:participantLink}).catch(function(){});}else{window.mhInviteCopyLink();}};'
        . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&window.location.hash==="#"+modalId){e.preventDefault();mhCloseInviteModal();}});'
        . 'document.addEventListener("click",function(e){var modal=document.getElementById(modalId);if(!modal||window.location.hash!=="#"+modalId){return;}if(e.target===modal){mhCloseInviteModal();}});'
        . '})();'
        . '</script>'
        . '</div></div>';

    mh_page_end();
}

function mh_handle_join(): void
{
    mh_require_login();
    $roomInput = isset($_GET['room_id']) ? (string)$_GET['room_id'] : '';
    $norm = mh_normalize_room($roomInput);
    $roomId = (string)($norm['room_id'] ?? '');
    $roomTitle = (string)($norm['title'] ?? '');
    $roleRaw = isset($_GET['role']) ? trim((string)$_GET['role']) : 'viewer';
    $role = ($roleRaw === 'participant') ? 'viewer' : $roleRaw;
    $name = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
    if ($name === '') {
        $name = trim(mh_current_user_name());
    }
    if ($name === '') {
        $name = mh_current_username();
    }

    if ($roomId === '') {
        http_response_code(400);
        echo 'missing room_id';
        return;
    }

    if ($name === '') {
        mh_render_invite_join_form($roomId, $roomTitle, $role);
        return;
    }

    $date = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
    $time = isset($_GET['time']) ? trim((string)$_GET['time']) : '';
    $showMeetingReady = false;

    $direct = isset($_GET['direct']) && (string)$_GET['direct'] === '1';

    $isAdmin = $role === 'presenter';
    $userId = ($isAdmin ? 'presenter_' : 'guest_') . bin2hex(random_bytes(8));
    $meetingCost = mh_meeting_cost_tokens();

    try {
        $username = mh_current_username();
        mh_apply_tenant_context('user:' . $username);
        $calDb = calendar_get_db();
        if ($calDb) {
            calendar_ensure_tables($calDb);
        }
        $existingMeeting = null;
        if ($calDb) {
            $existingMeeting = calendar_find_active_meeting_by_room($calDb, $roomId);
        }
        $existingMeetingId = (int)($existingMeeting['id'] ?? 0);

        if ($isAdmin && $existingMeetingId < 1) {
            $balance = mh_get_user_tokens($username);
            if ($balance < $meetingCost) {
                $backFallbackUrl = $role === 'presenter'
                    ? '/hub/meet/'
                    : ('/meet.php?room_id=' . rawurlencode($roomId) . '&role=' . rawurlencode($role));
                $safeBackFallbackUrl = json_encode($backFallbackUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);
                http_response_code(402);
                mh_page_start('Insufficient Tokens');
                echo '<div class="mh-center"><div class="mh-card narrow">'
                    . '<h1 class="mh-title">Insufficient Tokens</h1>'
                    . '<div class="mh-hint">Meeting creation costs ' . (int)$meetingCost . ' tokens. Your balance is ' . number_format($balance) . '.</div>'
                    . '<div class="mh-links" style="margin-top:14px"><a href="/hub/genesis/tokenization.php">Top up tokens</a><a href="#" onclick="if(window.history.length>1){history.back();return false;}if(document.referrer){window.location.href=document.referrer;return false;}window.location.href=' . $safeBackFallbackUrl . ';return false;">Back</a></div>'
                    . '</div></div>';
                mh_page_end();
                return;
            }
        }

        try {
            pnm_create_room_helper($roomId, $roomTitle);
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'exist') === false && strpos($msg, 'already') === false) {
                throw $e;
            }
        }

        $res = null;
        for ($i = 0; $i < 5; $i++) {
            $res = pnm_get_join_token_helper($roomId, $name, $userId, $isAdmin);
            if (is_array($res) && !empty($res['status']) && !empty($res['token'])) {
                break;
            }
            $msg = isset($res['msg']) ? strtolower((string)$res['msg']) : '';
            if (strpos($msg, 'room is not active') === false && strpos($msg, 'room not found') === false) {
                break;
            }
            pnm_create_room_helper($roomId, $roomTitle);
            usleep(300000);
        }

        if (!is_array($res) || empty($res['status']) || empty($res['token'])) {
            $m = is_array($res) && isset($res['msg']) ? (string)$res['msg'] : 'unknown error';
            throw new RuntimeException($m);
        }

        $host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string)$_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one');
        $host = trim(explode(',', $host)[0]);
        $ephemeralJoinUrl = 'https://' . $host . '/hub/meet/room.php?access_token=' . $res['token'] . '&room_id=' . rawurlencode($roomId);

        $inviteJoinRole = 'viewer';
        $presenterJoinRole = 'presenter';
        $inviteUrl = 'https://' . $host . '/meet.php?room_id=' . rawurlencode($roomId) . '&role=' . rawurlencode($inviteJoinRole);
        $presenterUrl = 'https://' . $host . '/meet.php?room_id=' . rawurlencode($roomId) . '&role=' . rawurlencode($presenterJoinRole);
        $joinUrl = $presenterUrl . '&name=' . rawurlencode($name) . '&direct=1';
        $scheduledText = '';
        if ($date !== '' || $time !== '') {
            $scheduledText = trim($date . ' ' . $time);
        }

        $newMeetingCreated = false;
        if ($isAdmin && $existingMeetingId < 1 && $calDb) {
            $newMeetingCreated = true;
            $showMeetingReady = !$direct && ($date !== '' || $time !== '');
            $scheduledUtc = null;
            if ($date !== '' && $time !== '') {
                try {
                    $dt = new DateTime($scheduledText, new DateTimeZone('UTC'));
                    $scheduledUtc = $dt->format('Y-m-d H:i:s');
                } catch (Throwable) {
                    $scheduledUtc = null;
                }
            }
            $meetingId = calendar_store_meeting([
                'room_id' => $roomId,
                'title' => $roomTitle !== '' ? $roomTitle : $roomId,
                'invite_url' => $inviteUrl,
                'presenter_join_url' => $presenterUrl,
                'participant_join_url' => $inviteUrl,
                'scheduled_for_utc' => $scheduledUtc,
                'scheduled_for_text' => $scheduledText,
                'created_by_user' => $username,
            ]);
            if ($meetingId > 0) {
                $due = (new DateTime('now', new DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d H:i:s');
                calendar_set_meeting_token_charge_pending($calDb, $meetingId, $meetingCost, $due);
            }

            try {
                $tenantId = 'user:' . $username;
                $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
                $rootPath = rtrim((string)getDataPath(), '/') . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
                if (!is_dir($rootPath)) {
                    @mkdir($rootPath, 0775, true);
                }
                $meta = [
                    'room_id' => $roomId,
                    'title' => $roomTitle,
                    'created_by' => $username,
                    'created_at_utc' => gmdate('c'),
                    'invite_url' => $inviteUrl,
                    'presenter_join_url' => $presenterUrl,
                    'scheduled_for_text' => $scheduledText,
                ];
                @file_put_contents($rootPath . '/meeting.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } catch (Throwable) {
            }
        }

        if (!$isAdmin || !$newMeetingCreated || !$showMeetingReady) {
            header('Location: ' . $ephemeralJoinUrl, true, 302);
            return;
        }

        mh_render_join_result($roomId, $roomTitle, $joinUrl, $presenterUrl, $inviteUrl, $scheduledText);
    } catch (Throwable $e) {
        http_response_code(500);
        mh_page_start('Meeting Error');
        echo '<div class="mh-center"><div class="mh-card narrow">'
            . '<h1 class="mh-title">Meeting Error</h1>'
            . '<div class="mh-hint">join failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div class="mh-links" style="margin-top:14px"><a href="/hub/meet/">Back to booking</a></div>'
            . '</div></div>';
        mh_page_end();
    }
}

if (isset($_GET['room_id']) || isset($_GET['name'])) {
    mh_handle_join();
} else {
    mh_render_booking_form();
}
