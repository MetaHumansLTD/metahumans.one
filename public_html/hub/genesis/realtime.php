<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

mh_widget_start_session();
$u = isset($_SESSION["mh_auth_user"]) ? trim((string)$_SESSION["mh_auth_user"]) : "";
if ($u === "") {
    $redirect = isset($_SERVER["REQUEST_URI"]) ? (string)$_SERVER["REQUEST_URI"] : "/hub/";
    if ($redirect === "" || $redirect[0] !== "/") { $redirect = "/hub/"; }
    header("Location: /auth/login.php?redirect=" . rawurlencode($redirect), true, 302);
    exit;
}

$ctx = mh_widget_require_auth();
$personaId = isset($_GET['persona_id']) ? trim((string)$_GET['persona_id']) : '';
$embed = isset($_GET['embed']) ? trim((string)$_GET['embed']) : '';
$embed = in_array(strtolower($embed), ['1', 'true', 'yes', 'on'], true);
$view = isset($_GET['view']) ? trim((string)$_GET['view']) : '';
$view = strtolower($view);
if ($view === '') { $view = 'avatar'; }
if ($personaId !== '') {
    $_SESSION['mh_selected_persona'] = $personaId;
    $ctx['persona_id'] = $personaId;
}
$personaId = $personaId !== '' ? $personaId : (string)($ctx['persona_id'] ?? 'default');
$personaId = $personaId !== '' ? $personaId : 'default';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realtime Persona</title>
    <?php if (!$embed): ?>
        <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <?php endif; ?>
    <style>
        body { background: #0a0a0a; color: #e6f6ff; font-family: 'Rajdhani', sans-serif; margin: 0; }
        .wrap { display:flex; justify-content:center; padding: <?php echo $embed ? '0' : '14px'; ?>; }
        .card { width:100%; max-width: <?php echo $embed ? 'none' : '980px'; ?>; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 14px; box-sizing: border-box; }
        .row { display:flex; gap: 10px; align-items:center; justify-content: space-between; flex-wrap: wrap; }
        .status { color: rgba(255,255,255,0.75); font-size: 13px; min-height: 18px; }
        .stage { margin-top: 12px; display:grid; grid-template-columns: 1fr; gap: 12px; }
        .avatarWrap { position: relative; width: 100%; aspect-ratio: 1 / 1; background:#000; border-radius: 12px; border: 1px solid rgba(255,255,255,0.12); overflow: hidden; }
        .avatar { position:absolute; inset:0; width:100%; height:100%; object-fit: cover; background:#000; }
          .avatarVoice { position:absolute; left:12px; bottom:12px; z-index:3; padding: 10px 12px; border-radius: 999px; }
        .chat { display:grid; grid-template-columns: 1fr; gap: 10px; }
        .input { width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; padding: 10px 12px; color: rgba(255,255,255,0.92); font-size: 14px; outline: none; box-sizing: border-box; }
        .actions { display:flex; gap: 10px; flex-wrap: wrap; }
        .btn { border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); color: rgba(255,255,255,0.92); padding: 10px 12px; border-radius: 10px; font-weight: 900; cursor: pointer; }
        .btn.primary { background: rgba(0,212,255,0.14); border-color: rgba(0,212,255,0.35); }
        .log { white-space: pre-wrap; font-size: 13px; color: rgba(255,255,255,0.85); background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.10); border-radius: 12px; padding: 10px 12px; min-height: 64px; }
    </style>
</head>
<body>
    <?php if (!$embed): ?>
        <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
        <main class="main-content">
    <?php endif; ?>
    <div class="wrap">
        <div class="card">
            <?php if (!$embed): ?>
                <div class="row">
                    <div>
                        <div style="color: rgba(255,255,255,0.6); font-size: 12px;">persona_id</div>
                        <div style="color: rgba(255,255,255,0.9); font-size: 14px;"><?php echo htmlspecialchars($personaId, ENT_QUOTES); ?></div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="status" id="status"></div>
            <div class="row" style="margin-top:10px">
                <button class="btn" id="clear" type="button">Clear</button>
            </div>
            <div class="stage">
                <?php if (!($embed && $view === 'chat')): ?>
                    <div class="avatarWrap" id="avatarWrap">
                        <img class="avatar" id="avatar" alt="persona avatar">
                    </div>
                <?php endif; ?>
                <?php if ($view === 'avatar'): ?>
                    <div class="log" id="log"></div>
                <?php endif; ?>
                <?php if ($view === 'chat'): ?>
                <div class="chat">
                    <textarea class="input" id="text" rows="3" placeholder="Ask the persona…"></textarea>
                    <div class="actions">
                        <button class="btn primary" id="send" type="button">Send</button>
                        <button class="btn" id="voice" type="button">Voice</button>
                    </div>
                    <audio id="replyAudio" controls style="width:100%; display:none;"></audio>
                    <div class="log" id="log"></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!$embed): ?>
        </main>
        <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
    <?php endif; ?>
    <script>
      (function () {
        var personaId = <?php echo json_encode($personaId, JSON_UNESCAPED_SLASHES); ?>;
        var view = <?php echo json_encode($view, JSON_UNESCAPED_SLASHES); ?>;
        var embed = <?php echo json_encode($embed, JSON_UNESCAPED_SLASHES); ?>;
        var alwaysOn = (view === "avatar");
        var enabled = !embed;
        var statusEl = document.getElementById("status");
        var avatarEl = document.getElementById("avatar");
        var textEl = document.getElementById("text");
        var sendBtn = document.getElementById("send");
        var voiceBtn = document.getElementById("voice");
        var logEl = document.getElementById("log");
        var avatarWrapEl = document.getElementById("avatarWrap");
        var replyAudioEl = document.getElementById("replyAudio");
        if (!replyAudioEl) { try { replyAudioEl = new Audio(); } catch (e) { replyAudioEl = null; } }
        var recording = null;
        var chunks = [];

          var transcript = [];

          function renderTranscript() { setLog(transcript.join("\n")); }
          function addLine(prefix, text) {
            var t = (text || "").trim();
            if (!t) return;
            transcript.push(prefix + ": " + t);
            if (transcript.length > 120) transcript = transcript.slice(-120);
            renderTranscript();
          }
          function looksHallucination(t) {
            var s = String(t || "").toLowerCase().replace(/[^a-z0-9'\s]+/g, " ").replace(/\s+/g, " ").trim();
            if (!s) return false;
            if (s.length < 6) return true;
            if (/(^|\s)(thank you)(\s|$)/.test(s) && s.split("thank").length > 3) return true;
            if (/(^|\s)(i'?m sorry)(\s|$)/.test(s) && s.split("sorry").length > 3) return true;
            var words = s.split(" ").filter(Boolean);
            if (words.length < 4) return false;
            var allow = { "thank":1,"you":1,"im":1,"i'm":1,"sorry":1,"hello":1,"okay":1,"ok":1,"oh":1,"love":1,"need":1,"check":1,"here":1,"and":1,"youre":1,"you're":1,"open":1,"see":1,"next":1,"time":1 };
            var uniq = {};
            var bad = 0;
            for (var i = 0; i < words.length; i++) {
              var w = words[i];
              uniq[w] = 1;
              if (!allow[w]) bad++;
            }
            var uniqCount = Object.keys(uniq).length;
            if (words.length > 16 && uniqCount <= 6) return true;
            if (words.length > 30 && uniqCount <= 10) return true;
            if (bad === 0 && uniqCount <= 6) return true;
            return false;
          }


        function setStatus(s) { if (statusEl) statusEl.textContent = s || ""; }
        function setLog(t) { if (logEl) logEl.textContent = t || ""; }

        function clearTranscript() {
          try { transcript = []; } catch (e) {}
          try { if (logEl) logEl.textContent = ""; } catch (e) {}
          try { if (textEl) textEl.value = ""; } catch (e) {}
          try { if (replyAudioEl) { replyAudioEl.pause(); replyAudioEl.removeAttribute("src"); replyAudioEl.style.display = "none"; } } catch (e) {}
          setStatus(alwaysOn ? (enabled ? "Persona live." : "Persona paused.") : "Ready.");
        }


        function avatarUrl() {
          return "/hub/genesis/persona-images.php?persona=" + encodeURIComponent(personaId) + "&v=" + Date.now();
        }

        async function postJson(url, payload) {
          var res = await fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/json", "Accept": "application/json" },
            credentials: "include",
            body: JSON.stringify(payload || {})
          });
          var raw = await res.text();
          var j = null;
          try { j = JSON.parse(raw); } catch (e) { j = null; }
          return { ok: res.ok, status: res.status, json: j, raw: raw };
        }

        async function askText(forcedText) {
          var t = textEl ? (textEl.value || "").trim() : "";
          if (!t) return;
            if (alwaysOn && live && live.inFlight) {
              setStatus("Responding…");
              return;
            }
            try { if (alwaysOn && live && live.recorder) live.recorder.stop(); } catch (e) {}
            addLine("You", t);
            setStatus("Thinking…");
          renderTranscript();
          if (replyAudioEl) { replyAudioEl.pause(); replyAudioEl.removeAttribute("src"); replyAudioEl.style.display = "none"; }
          if (alwaysOn && live) live.inFlight = true;
          var r = null;
          try {
            r = await postJson("/hub/widget/persona/chat/", { persona_id: personaId, text: t });
          } finally {
            if (alwaysOn && live) {
              live.inFlight = false;
              setTimeout(beginLiveChunk, 250);
            }
          }
          if (!r.json || r.json.success !== true) {
            setStatus("Chat failed: " + ((r.json && r.json.error) ? r.json.error : ""));
            return;
          }
          var replyText = r.json.text || "";
          var audioUrl = r.json.audio_url || "";
          addLine("Persona", replyText);
          if (audioUrl && replyAudioEl) {
            replyAudioEl.src = audioUrl;
            replyAudioEl.style.display = "block";
            try { await replyAudioEl.play(); } catch (e) {}
          } else if (replyText && window.speechSynthesis && window.SpeechSynthesisUtterance) {
            try {
              window.speechSynthesis.cancel();
              var u = new SpeechSynthesisUtterance(replyText);
              window.speechSynthesis.speak(u);
            } catch (e) {}
          }
          loadHistory();
          setStatus(alwaysOn ? (enabled ? "Persona live." : "Persona paused.") : "Ready.");
        }

        async function sendVoice(blob) {
          setStatus("Transcribing…");
          renderTranscript();

          try {
            if (replyAudioEl) { replyAudioEl.pause(); replyAudioEl.removeAttribute("src"); replyAudioEl.style.display = "none"; }

            var fd = new FormData();
            fd.append("persona_id", personaId);
            fd.append("text", "");
            fd.append("audio", blob, "voice.webm");
            fd.append("no_tts", "1");

            var ctrl = null;
            var timeoutId = null;
            if (window.AbortController) {
              ctrl = new AbortController();
              timeoutId = setTimeout(function () {
                try { ctrl.abort(); } catch (e) {}
              }, 180000);
            }

            var res = await fetch("/hub/widget/persona/chat/", {
              method: "POST",
              body: fd,
              credentials: "include",
              signal: ctrl ? ctrl.signal : undefined,
            });

            if (timeoutId) { try { clearTimeout(timeoutId); } catch (e) {} }

            var ct = "";
            try { ct = String(res.headers.get("content-type") || ""); } catch (e) { ct = ""; }

            var raw = await res.text();
            var j = null;
            try { j = JSON.parse(raw); } catch (e) { j = null; }

            if (!res.ok) {
              addLine("System", "HTTP " + String(res.status) + " " + String(res.statusText || "") + (ct ? " (" + ct + ")" : ""));
            }

            if (!j) {
              var snip = raw ? String(raw).slice(0, 240) : "";
              addLine("System", "Non-JSON response" + (ct ? " (" + ct + ")" : "") + (snip ? ": " + snip : ""));
              setStatus("Chat failed (invalid response).");
              return;
            }

            if (j.success !== true) {
              var err = j.error ? String(j.error) : "";
              addLine("System", "Chat failed" + (err ? ": " + err : ""));
              setStatus("Chat failed" + (err ? ": " + err : "."));
              return;
            }
            if (j.ignored) {
              setStatus("Listening…");
              return;
            }

            var heard = j.transcript ? String(j.transcript || "").trim() : "";
            if (heard && !looksHallucination(heard)) {
              addLine("You", heard);
            }


            var replyText = j.text || "";
            var audioUrl = j.audio_url || "";
            addLine("Persona", replyText);

            if (audioUrl && replyAudioEl) {
              replyAudioEl.src = audioUrl;
              replyAudioEl.style.display = "block";
              try { await replyAudioEl.play(); } catch (e) {}
            }

            loadHistory();
            setStatus(alwaysOn ? (enabled ? "Persona live." : "Persona paused.") : "Ready.");
          } catch (e) {
            var msg = "";
            try { msg = e && e.name === "AbortError" ? "Timeout waiting for response." : String(e && e.message ? e.message : e); } catch (x) { msg = "Request failed."; }
            addLine("System", msg);
            setStatus(msg);
          }
        }

        function toggleVoice() {
          if (recording) {
            try { recording.stop(); } catch (e) {}
            return;
          }

          if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
            setStatus("Voice not supported.");
            return;
          }

          chunks = [];
          navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            recording = new MediaRecorder(stream);
            recording.ondataavailable = function (e) { try { if (e && e.data && e.data.size > 0) chunks.push(e.data); } catch (x) {} };
            recording.onstop = function () {
              try { stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} }); } catch (e) {}
              var blob = new Blob(chunks, { type: "audio/webm" });
              recording = null;
              if (voiceBtn) voiceBtn.textContent = "Voice";
              if (blob.size < 2000) { setStatus("No voice captured."); return; }
              sendVoice(blob);
            };
            recording.start();
            if (voiceBtn) voiceBtn.textContent = "Stop";
            setStatus("Recording…");
          }).catch(function () {
            setStatus("Microphone permission denied.");
          });
        }


        var live = {
            stream: null,
            recorder: null,
            chunks: [],
            stopTimer: null,
            inFlight: false,
            audioCtx: null,
            analyser: null,
            analyserData: null,
            vadTimer: null,
            vadSpeechMs: 0,
            vadLastSpeechAt: 0,
            vadLastT: 0,
          };

        function postStatus(st) {
          try {
            if (window.parent && window.parent !== window) {
              window.parent.postMessage({ type: "mh_realtime_status", persona_id: personaId, status: st, view: view }, window.location.origin);
            }
          } catch (e) {}
        }
          function stopLiveCapture() {
            try { if (live.stopTimer) clearTimeout(live.stopTimer); } catch (e) {}
            live.stopTimer = null;

            try { if (live.vadTimer) clearInterval(live.vadTimer); } catch (e) {}
            live.vadTimer = null;
            live.vadSpeechMs = 0;
            live.vadLastSpeechAt = 0;
            live.vadLastT = 0;

            try { if (live.recorder) live.recorder.onstop = null; } catch (e) {}
            try { if (live.recorder) live.recorder.stop(); } catch (e) {}
            live.recorder = null;
            live.chunks = [];

            try {
              if (live.stream) {
                live.stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
              }
            } catch (e) {}
            live.stream = null;

            try { if (live.audioCtx && live.audioCtx.state !== 'closed') live.audioCtx.close(); } catch (e) {}
            live.audioCtx = null;
            live.analyser = null;
            live.analyserData = null;
          }
          function beginLiveChunk() {
            if (!alwaysOn || !enabled) return;
            if (!live.stream) return;
            if (live.recorder) return;
            if (live.inFlight) { setStatus("Responding…"); return; }

            live.chunks = [];
            live.vadSpeechMs = 0;
            live.vadLastSpeechAt = 0;
            live.vadLastT = 0;

            var vadThreshold = 0.04;
            var vadMinSpeechMs = 320;
            var vadHangMs = 500;

            var rec;
            try {
              rec = new MediaRecorder(live.stream);
            } catch (e) {
              setStatus("Voice not supported.");
              return;
            }
            live.recorder = rec;

            try { if (live.vadTimer) clearInterval(live.vadTimer); } catch (e) {}
            live.vadTimer = null;
            if (live.analyser && live.analyserData) {
              live.vadLastT = (window.performance && performance.now) ? performance.now() : Date.now();
              live.vadTimer = setInterval(function () {
                try {
                  var an = live.analyser;
                  var data = live.analyserData;
                  an.getByteTimeDomainData(data);
                  var sum = 0;
                  for (var i = 0; i < data.length; i++) {
                    var v = (data[i] - 128) / 128;
                    sum += v * v;
                  }
                  var rms = Math.sqrt(sum / data.length);
                  var now = (window.performance && performance.now) ? performance.now() : Date.now();
                  var dt = now - (live.vadLastT || now);
                  live.vadLastT = now;
                  if (rms > vadThreshold) {
                    live.vadSpeechMs += dt;
                    live.vadLastSpeechAt = now;
                  }
                } catch (x) {}
              }, 60);
            }

            rec.ondataavailable = function (e) {
              try { if (e && e.data && e.data.size > 0) live.chunks.push(e.data); } catch (x) {}
            };

            rec.onstop = function () {
              try { if (live.vadTimer) clearInterval(live.vadTimer); } catch (e) {}
              live.vadTimer = null;

              var blob = null;
              try { blob = new Blob(live.chunks, { type: "audio/webm" }); } catch (e) {}
              live.recorder = null;
              live.chunks = [];

              if (!alwaysOn || !enabled) return;

              var now = (window.performance && performance.now) ? performance.now() : Date.now();
              var speechOk = live.vadSpeechMs >= vadMinSpeechMs;
              if (!speechOk && live.vadLastSpeechAt) {
                if ((now - live.vadLastSpeechAt) <= vadHangMs) speechOk = true;
              }
              live.vadSpeechMs = 0;
              live.vadLastSpeechAt = 0;
              live.vadLastT = 0;

              if (!blob || blob.size < 12000 || !speechOk) {
                setStatus("Listening…");
                setTimeout(beginLiveChunk, 250);
                return;
              }

              live.inFlight = true;
              sendVoice(blob)
                .catch(function () {})
                .finally(function () {
                  live.inFlight = false;
                  setTimeout(beginLiveChunk, 250);
                });
            };

            try { rec.start(); } catch (e) { live.recorder = null; return; }

            try {
              live.stopTimer = setTimeout(function () {
                if (live.recorder) { try { live.recorder.stop(); } catch (e) {} }
              }, 4200);
            } catch (e) {}
          }
          async function startLiveCapture() {
            if (!alwaysOn || !enabled) return;
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
              setStatus("Voice not supported.");
              return;
            }

            if (live.stream || live.recorder) return;

            setStatus("Persona live.");
            postStatus("activated");

            try {
              live.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
              setStatus("Microphone permission denied.");
              return;
            }

            try {
              var AC = window.AudioContext || window.webkitAudioContext;
              if (AC) {
                live.audioCtx = new AC();
                var src = live.audioCtx.createMediaStreamSource(live.stream);
                var an = live.audioCtx.createAnalyser();
                an.fftSize = 1024;
                an.smoothingTimeConstant = 0.8;
                src.connect(an);
                live.analyser = an;
                live.analyserData = new Uint8Array(an.fftSize);
              }
            } catch (e) {
              live.audioCtx = null;
              live.analyser = null;
              live.analyserData = null;
            }

            beginLiveChunk();
          }

          function setEnabled(v) {
          enabled = !!v;
          if (!alwaysOn) return;
          if (enabled) {
            startLiveCapture();
            setStatus("Persona live.");
            postStatus("activated");
          } else {
            stopLiveCapture();
            setStatus("Persona paused.");
            postStatus("deactivated");
          }
        }

        try {
          window.addEventListener("message", function (ev) {
            try {
              if (!ev || ev.origin !== window.location.origin) return;
              var d = ev.data;
              if (!d || typeof d !== "object") return;
              if (d.type !== "mh_realtime_command") return;
              if (d.cmd === "activate") setEnabled(true);
              if (d.cmd === "deactivate") setEnabled(false);
            } catch (e) {}
          });
        } catch (e) {}
          async function loadHistory() {
            try {
              var url = "/hub/widget/persona/chat/?action=history&limit=40&persona_id=" + encodeURIComponent(personaId);
              var res = await fetch(url, { credentials: "include" });
              var raw = await res.text();
              var j = null;
              try { j = JSON.parse(raw); } catch (e) { j = null; }
              if (!j || j.success !== true || !Array.isArray(j.events)) return;
              transcript = [];
              j.events.forEach(function (ev) {
                if (!ev || typeof ev !== "object") return;
                var kind = String(ev.kind || "").toLowerCase();
                var text = String(ev.text || "");
                if (!text) return;
                if (kind === "user" && looksHallucination(text)) return;
                if (kind === "user") addLine("You", text);
                if (kind === "assistant") addLine("Persona", text);
              });
            } catch (e) {
            }
          }

        if (avatarEl) avatarEl.src = avatarUrl();
        if (sendBtn) sendBtn.addEventListener("click", function () { askText(); });
        if (voiceBtn) voiceBtn.addEventListener("click", function () { toggleVoice(); });
        var clearBtn = document.getElementById("clear");
        if (clearBtn) clearBtn.addEventListener("click", function () { clearTranscript(); });
        loadHistory();
          setStatus(view === "avatar" ? (enabled ? "Persona live." : "Persona paused.") : "Ready.");
          if (alwaysOn && enabled) { try { startLiveCapture(); } catch (e) {} }
          try {
            if (window.parent && window.parent !== window) {
              window.parent.postMessage({ type: "mh_realtime_status", persona_id: personaId, status: "ready", view: view }, window.location.origin);
            }
          } catch (e) {}
        })();
    </script>
</body>
</html>
