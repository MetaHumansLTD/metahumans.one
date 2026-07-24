<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

$token = isset($_GET['access_token']) ? trim((string)$_GET['access_token']) : '';
$lkUrl = isset($_GET['lk_url']) ? trim((string)$_GET['lk_url']) : '';
if ($lkUrl === '') {
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : 'metahumans.one';
    $lkUrl = 'wss://' . $host . '/rtc';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meet Overlay</title>
  <style>
    body{margin:0;background:#0b1020;color:#e8eefc;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial}
    .wrap{max-width:980px;margin:0 auto;padding:18px}
    .card{border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(0,0,0,.25);padding:14px}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .btn{border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#e8eefc;padding:10px 12px;font-weight:900;cursor:pointer}
    .input{width:100%;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:#e8eefc;padding:10px 12px}
    .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;opacity:.9}
    .log{margin-top:12px;display:flex;flex-direction:column;gap:10px}
    .msg{border-radius:12px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);padding:10px 12px}
    .t{font-weight:950}
    .s{opacity:.75;font-size:12px;margin-top:4px}
  </style>
  <script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.min.js"></script>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="row">
        <div style="flex:1;min-width:260px">
          <div class="mono">LiveKit URL</div>
          <input id="lkUrl" class="input" value="<?php echo htmlspecialchars($lkUrl, ENT_QUOTES); ?>">
        </div>
        <div style="flex:1;min-width:260px">
          <div class="mono">Access Token</div>
          <input id="token" class="input" value="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>">
        </div>
        <div>
          <button class="btn" onclick="connect()">Connect</button>
          <button class="btn" onclick="disconnect()">Disconnect</button>
        </div>
      </div>
      <div class="mono" style="margin-top:10px">Shows LiveKit data packets for topics starting with <b>mh.</b></div>
      <div id="status" class="mono" style="margin-top:8px">disconnected</div>
      <div id="log" class="log"></div>
    </div>
  </div>
  <script>
    let room = null;
    function el(id){return document.getElementById(id);}
    function addMsg(title, text, meta){
      const d=document.createElement('div');
      d.className='msg';
      const t=document.createElement('div');
      t.className='t';
      t.textContent=title;
      const c=document.createElement('div');
      c.textContent=text;
      const s=document.createElement('div');
      s.className='s';
      s.textContent=meta||'';
      d.appendChild(t);d.appendChild(c);if(meta)d.appendChild(s);
      el('log').prepend(d);
    }
    async function connect(){
      const url=el('lkUrl').value.trim();
      const token=el('token').value.trim();
      if(!url||!token){el('status').textContent='missing url/token';return;}
      if(room){try{await room.disconnect();}catch(e){} room=null;}
      room = new LivekitClient.Room({adaptiveStream:true,dynacast:true});
      room.on(LivekitClient.RoomEvent.Connected, ()=>{el('status').textContent='connected '+room.name;});
      room.on(LivekitClient.RoomEvent.Disconnected, ()=>{el('status').textContent='disconnected';});
      room.on(LivekitClient.RoomEvent.DataReceived, (payload, participant, kind, topic)=>{
        try{
          const t=String(topic||'');
          if(t.indexOf('mh.')!==0)return;
          const txt=new TextDecoder().decode(payload);
          let out=txt;
          try{ const j=JSON.parse(txt); out = j.text ? String(j.text) : txt; }catch(e){}
          addMsg(t, out, participant && participant.identity ? participant.identity : '');
        }catch(e){}
      });
      try{
        await room.connect(url, token);
      }catch(e){
        el('status').textContent='connect failed';
      }
    }
    async function disconnect(){
      if(!room)return;
      try{await room.disconnect();}catch(e){}
      room=null;
      el('status').textContent='disconnected';
    }
  </script>
</body>
</html>

