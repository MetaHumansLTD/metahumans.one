<?php
declare(strict_types=1);

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

$templates = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__, 2) . '/templates');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meeting Diagnostics</title>
  <?php if (is_file($templates . '/global-ui/includes/complete-head.php')) include_once $templates . '/global-ui/includes/complete-head.php'; ?>
  <style>
    html,body{background:#050816;color:#f9fafb}
    main{max-width:1100px;margin:0 auto;padding:18px 20px}
    .card{border-radius:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);backdrop-filter:blur(6px);padding:16px;margin:12px 0}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#e8eefc;padding:8px 10px;font-weight:900;cursor:pointer;text-decoration:none;font-size:12px}
    .btn.primary{border-color:rgba(0,212,255,.35);background:rgba(0,212,255,.16);color:#d7fbff}
    .input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:rgba(255,255,255,.92)}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}
    .muted{opacity:.75;font-size:12px}
    pre{white-space:pre-wrap;word-break:break-word;margin:0}
  </style>
</head>
<body>
<?php if (is_file($templates . '/global-ui/includes/complete-body-start.php')) include_once $templates . '/global-ui/includes/complete-body-start.php'; ?>
<main>
  <div class="row" style="justify-content:space-between">
    <div>
      <div style="font-size:20px;font-weight:950">Meeting Diagnostics</div>
      <div class="muted">Run this on the same device/network that is having issues joining or lagging.</div>
    </div>
    <div class="row" style="justify-content:flex-end">
      <a class="btn" href="/hub/meet/">Back</a>
      <a class="btn" href="/hub/meet/room.php?access_token=" onclick="alert('Paste a real access_token first'); return false;">Room wrapper</a>
    </div>
  </div>

  <div class="card">
    <div style="font-weight:950;margin-bottom:8px">Client-side checks</div>
    <div class="grid">
      <div>
        <div class="muted">WebSocket URL (LiveKit)</div>
        <input id="wsUrl" class="input mono" value="">
        <div class="row" style="margin-top:10px">
          <button class="btn primary" type="button" onclick="runWs()">Test WebSocket</button>
          <button class="btn" type="button" onclick="runIce()">Test WebRTC ICE</button>
        </div>
        <div class="muted" style="margin-top:10px">Expected: WS connects quickly; ICE gathers host/srflx (and possibly relay if TURN works).</div>
      </div>
      <div>
        <div class="muted">Network info</div>
        <pre id="netInfo" class="mono"></pre>
      </div>
    </div>
  </div>

  <div class="card">
    <div style="font-weight:950;margin-bottom:8px">Server-side checks (from metahumans.one)</div>
    <div class="row">
      <button class="btn primary" type="button" onclick="runServer()">Run server checks</button>
      <span class="muted">Checks reachability of PlugNMeet endpoints and client-config JSON.</span>
    </div>
    <pre id="serverOut" class="mono" style="margin-top:10px"></pre>
  </div>

  <div class="card">
    <div style="font-weight:950;margin-bottom:8px">Output</div>
    <pre id="out" class="mono"></pre>
  </div>
</main>
<?php if (is_file($templates . '/global-ui/includes/complete-body-end.php')) include_once $templates . '/global-ui/includes/complete-body-end.php'; ?>
<script>
function el(id){return document.getElementById(id);}
function log(msg){ el('out').textContent = (el('out').textContent || '') + msg + '\\n'; }
function setNet(){
  const n = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const o = {
    userAgent: navigator.userAgent,
    online: navigator.onLine,
    connection: n ? {
      effectiveType: n.effectiveType,
      downlink: n.downlink,
      rtt: n.rtt,
      saveData: n.saveData
    } : null
  };
  el('netInfo').textContent = JSON.stringify(o, null, 2);
}
setNet();

function defaultWsUrl(){
  const host = location.host;
  return 'wss://' + host + '/rtc';
}
el('wsUrl').value = defaultWsUrl();

async function runWs(){
  log('--- WebSocket test ---');
  const url = el('wsUrl').value.trim();
  if(!url){ log('missing ws url'); return; }
  const start = performance.now();
  let ws = null;
  try{
    ws = new WebSocket(url);
  }catch(e){
    log('WS constructor failed: ' + String(e && e.message || e));
    return;
  }
  const t = setTimeout(()=>{ try{ ws.close(); }catch(e){} }, 6000);
  ws.onopen = () => {
    const ms = Math.round(performance.now() - start);
    log('WS open in ' + ms + 'ms');
    try{ ws.close(); }catch(e){}
  };
  ws.onerror = () => {
    const ms = Math.round(performance.now() - start);
    log('WS error after ' + ms + 'ms');
    clearTimeout(t);
  };
  ws.onclose = (ev) => {
    const ms = Math.round(performance.now() - start);
    log('WS closed after ' + ms + 'ms code=' + ev.code + ' reason=' + (ev.reason||''));
    clearTimeout(t);
  };
}

async function runIce(){
  log('--- WebRTC ICE test ---');
  if(!window.RTCPeerConnection){ log('RTCPeerConnection not supported'); return; }
  const pc = new RTCPeerConnection({iceServers:[{urls:'stun:stun.l.google.com:19302'}]});
  const candidates = [];
  pc.onicecandidate = (e) => { if(e && e.candidate) candidates.push(e.candidate.candidate); };
  pc.onicegatheringstatechange = () => { log('iceGatheringState=' + pc.iceGatheringState); };
  try{
    pc.createDataChannel('diag');
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await new Promise(res => setTimeout(res, 2500));
    const types = {host:0,srflx:0,relay:0};
    for(const c of candidates){
      if(c.includes(' typ host')) types.host++;
      if(c.includes(' typ srflx')) types.srflx++;
      if(c.includes(' typ relay')) types.relay++;
    }
    log('candidates: host=' + types.host + ' srflx=' + types.srflx + ' relay=' + types.relay);
  }catch(e){
    log('ICE error: ' + String(e && e.message || e));
  }finally{
    try{ pc.close(); }catch(e){}
  }
}

async function runServer(){
  el('serverOut').textContent = 'running…';
  try{
    const res = await fetch('/hub/meet/diagnostics_api.php', {credentials:'include'});
    const ct = (res.headers.get('content-type')||'').toLowerCase();
    const txt = await res.text();
    if(!ct.includes('application/json')){
      el('serverOut').textContent = JSON.stringify({ok:false, status: res.status, content_type: ct, body_sample: txt.slice(0, 300)}, null, 2);
      return;
    }
    const data = JSON.parse(txt);
    el('serverOut').textContent = JSON.stringify(data, null, 2);
  }catch(e){
    el('serverOut').textContent = 'failed: ' + String(e && e.message || e);
  }
}
</script>
</body>
</html>
