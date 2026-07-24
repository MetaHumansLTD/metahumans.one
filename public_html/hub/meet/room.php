<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/_ide_stubs.php')) require_once __DIR__ . '/_ide_stubs.php';

require_once dirname(__DIR__, 2) . '/.cue/cue.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    $redir = '/auth/login.php';
    $qs = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if ($qs !== '') $redir .= '?redirect=' . rawurlencode($qs);
    header('Location: ' . $redir, true, 302);
    exit;
}

$token = isset($_GET['access_token']) ? trim((string)$_GET['access_token']) : '';
if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Missing access_token';
    exit;
}

$roomId = isset($_GET['room_id']) ? trim((string)$_GET['room_id']) : '';
$embed = isset($_GET['embed']) ? trim((string)$_GET['embed']) : '';
$embed = in_array(strtolower($embed), ['1','true','yes','on'], true);
$meetSrc = '/plugnmeet/?access_token=' . rawurlencode($token);

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: frame-ancestors 'self'");
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meeting Room</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <script>
  (function(){
    try{
      if(window.top !== window.self){
        const qs=new URLSearchParams(window.location.search||'');
        const tok=(qs.get('access_token')||'').trim();
        if(tok){
          window.location.replace('/meet/?access_token=' + encodeURIComponent(tok));
        }
      }
    }catch(e){}
  })();
  </script>
  <style>
    html,body{height:100%;margin:0;background:#050816;color:#f9fafb}
    .wrap{height:100%;position:relative;overflow:hidden}
    .frameWrap{position:absolute;inset:0;overflow:hidden}
    iframe{position:absolute;inset:0;width:100%;height:100%;border:0;transform-origin:0 0;background:#000}
  </style>
</head>
<body>
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<div class="wrap" id="mhWrap">
  <div class="frameWrap">
    <iframe id="mhMeetFrame" src="<?php echo htmlspecialchars($meetSrc, ENT_QUOTES); ?>" allow="camera; microphone; fullscreen; display-capture" allowfullscreen></iframe>
  </div>
</div>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
<script src="/hub/meet/assets/js/mh-ai-voice.js?v=<?php echo (string)time(); ?>" async></script>
<script src="/hub/meet/persona-bot.js?v=<?php echo (string)time(); ?>" async></script>
<script>
function mhSharePiP(){
  try{
    const f=document.getElementById('mhMeetFrame');
    if(!f || !f.contentWindow) return;
    f.contentWindow.postMessage({type:'mh_pip_share'}, '*');
  }catch(e){}
}

function mhInjectTools(){
  const frame = document.getElementById('mhMeetFrame');
  if(!frame) return;
  try{
    const doc = frame.contentDocument;
    if(!doc) return;
    mhCleanupFrameOverlays(doc);
    if(!doc.getElementById('mh-nats-auth-fix')){
      const s = doc.createElement('script');
      s.id = 'mh-nats-auth-fix';
      s.src = '/hub/meet/assets/js/nats-auth-fix.js?v=' + Date.now();
      s.async = true;
      (doc.head || doc.documentElement).appendChild(s);
    }
    if(!doc.getElementById('mh-config-override')){
      const s3 = doc.createElement('script');
      s3.id = 'mh-config-override';
      s3.src = '/hub/meet/assets/js/mh-config-override.js?v=' + Date.now();
      s3.async = true;
      (doc.head || doc.documentElement).appendChild(s3);
    }
  }catch(e){
  }
}

function mhCleanupFrameOverlays(doc){
  try{
    const ids = [
      'mh-livekit-inroom-style',
      'mh-livekit-inroom-toggle',
      'mh-livekit-inroom-panel',
      'mh-livekit-inroom-toggle-v2',
      'mh-livekit-inroom-panel-v2',
      'mh-ai-voice-fixed-btn',
      'mh-meet-autobot'
    ];
    for(const id of ids){
      const el = doc.getElementById(id);
      if(el && el.parentNode) el.parentNode.removeChild(el);
    }
    const prefixEls = Array.from(doc.querySelectorAll('[id^="mh-livekit-inroom-"]'));
    for(const el of prefixEls){
      if(el && el.parentNode) el.parentNode.removeChild(el);
    }
    const btns = Array.from(doc.querySelectorAll('button'));
    for (const b of btns) {
      try {
        if (!b || b.id) continue;
        const txt = (b.textContent || '').trim().toLowerCase();
        if (txt !== 'metahumans') continue;
        const cs = doc.defaultView && doc.defaultView.getComputedStyle ? doc.defaultView.getComputedStyle(b) : null;
        if (!cs) continue;
        if (cs.position !== 'fixed') continue;
        if (cs.right === 'auto' || cs.bottom === 'auto') continue;
        if (b.parentNode) b.parentNode.removeChild(b);
      } catch (e) {}
    }

    const divs = Array.from(doc.querySelectorAll('div'));
    for (const d of divs) {
      try {
        if (!d || d.id) continue;
        const t = (d.textContent || '').toUpperCase();
        if (!t.includes('METAHUMANS LIVE')) continue;
        const hasClear = !!d.querySelector('button') && (Array.from(d.querySelectorAll('button')).some(b => ((b.textContent||'').trim().toLowerCase()==='clear')));
        if (!hasClear) continue;
        const cs = doc.defaultView && doc.defaultView.getComputedStyle ? doc.defaultView.getComputedStyle(d) : null;
        if (!cs) continue;
        if (cs.position !== 'fixed') continue;
        if (cs.right === 'auto' || cs.bottom === 'auto') continue;
        if (d.parentNode) d.parentNode.removeChild(d);
      } catch (e) {}
    }
  }catch(e){}
}


(function(){
  const frame = document.getElementById('mhMeetFrame');
  if(!frame) return;
  const tick = () => { try{ mhInjectTools(); }catch(e){} };
  frame.addEventListener('load', tick);
  tick();
  if(!window.__mhMeetInjectTimer){
    window.__mhMeetInjectTimer = setInterval(tick, 800);
  }
})();
</script>
</body>
</html>
