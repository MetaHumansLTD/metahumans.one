<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

mh_id_start_session();
$user = mh_id_current_user();
$tokenParam = isset($_GET['t']) ? trim((string)$_GET['t']) : (isset($_GET['token']) ? trim((string)$_GET['token']) : '');
$tokenParam = preg_match('/^[a-f0-9]{16,}$/i', $tokenParam) ? $tokenParam : '';
if ($user === '' && $tokenParam === '') {
    header('Location: /auth/login.php?redirect=' . urlencode('/auth/id/capture.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KYC Live Video Capture</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-wrap { max-width: 1100px; margin: 0 auto; padding: 28px 18px; }
        .mh-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 18px; }
        .mh-row { display:flex; gap: 14px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
        .mh-title { margin:0 0 6px 0; color:#00d4ff; }
        .mh-muted { color:#9aa; font-size: 12px; }
        .mh-btn { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; cursor:pointer; font-weight:700; }
        .mh-btn-primary { background: #0aa0b6; border-color: rgba(0,212,255,0.35); }
        .mh-grid { display:grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
        @media (min-width: 980px) { .mh-grid { grid-template-columns: 1fr 1fr; } }
        .mh-field label { display:block; margin: 12px 0 6px; color:#cfefff; font-size: 12px; }
        .mh-field select { width: 100%; box-sizing:border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
        video { width: 100%; border-radius: 14px; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); }
        .mh-status { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px; margin-top: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px; }
        .mh-steps { margin-top: 12px; display:grid; gap: 10px; }
        .mh-step { border: 1px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.18); border-radius: 12px; padding: 10px 12px; }
        .mh-step-row { display:flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .mh-step-title { font-weight: 800; color:#e6f6ff; }
        .mh-step-state { font-weight: 800; font-size: 12px; border-radius: 999px; padding: 4px 10px; border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.06); color:#cfefff; }
        .mh-step.ok { border-color: rgba(16,185,129,0.35); background: rgba(16,185,129,0.08); }
        .mh-step.err { border-color: rgba(239,68,68,0.35); background: rgba(239,68,68,0.08); }
        .mh-step.working { border-color: rgba(0,212,255,0.35); background: rgba(0,212,255,0.06); }
        .mh-step-sub { margin-top: 6px; font-size: 12px; color:#9aa; white-space: pre-wrap; word-break: break-word; }
        details.mh-details { margin-top: 12px; }
        details.mh-details > summary { cursor: pointer; color:#cfefff; font-weight: 800; }
        .mh-summary { margin-top: 10px; background: rgba(0,0,0,0.18); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px; color:#e6f6ff; font-size: 14px; }
        .mh-summary .mh-summary-row { display:flex; gap: 10px; justify-content: space-between; flex-wrap: wrap; padding: 6px 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .mh-summary .mh-summary-row:last-child { border-bottom: 0; }
        .mh-summary .mh-summary-k { color:#9aa; font-weight: 800; }
        .mh-summary .mh-summary-v { color:#e6f6ff; font-weight: 800; word-break: break-word; }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <div class="mh-wrap">
        <div class="mh-row" style="margin-bottom: 14px;">
            <div>
                <h1 class="mh-title">Live Selfie Video Capture (KYC)</h1>
                <div class="mh-muted">Select front/back camera. Records a short liveness clip and uploads as mp4 evidence.</div>
            </div>
            <div class="mh-muted">
                <?php if ($user !== ''): ?>
                    Signed in as <?php echo htmlspecialchars($user, ENT_QUOTES); ?>
                <?php else: ?>
                    Token session capture
                <?php endif; ?>
            </div>
        </div>

        <div class="mh-grid">
            <div class="mh-card">
                <div class="mh-field">
                    <label>Camera device</label>
                    <select id="mhCam"></select>
                </div>
                <div class="mh-field">
                    <label>Prefer</label>
                    <select id="mhFacing">
                        <option value="user">Front (selfie)</option>
                        <option value="environment">Back</option>
                    </select>
                </div>
                <div style="margin-top: 12px; display:flex; gap: 10px; flex-wrap: wrap;">
                    <button class="mh-btn" id="mhStartCam" type="button">Start Camera</button>
                    <button class="mh-btn mh-btn-primary" id="mhRec" type="button" disabled>Record your image</button>
                </div>
                <div class="mh-muted" style="margin-top:10px;">Start the camera and then click record to complete the process.</div>
                <div class="mh-muted" id="mhBusy" style="margin-top:10px; display:none;">In process…</div>
                <div class="mh-steps" id="mhSteps"></div>
                <details class="mh-details" id="mhTechDetails" style="display:none;">
                    <summary>Technical details</summary>
                    <div class="mh-status" id="mhStatus">Idle</div>
                </details>
                <div class="mh-card" id="mhPostActions" style="display:none; margin-top: 12px; padding: 12px;">
                    <div style="font-weight:800; color:#e6f6ff;">Result</div>
                    <div class="mh-muted" style="margin-top:6px;">Return to the claim verification page when you are done.</div>
                    <div class="mh-summary" id="mhResultSummary">-</div>
                    <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
                        <a class="mh-btn mh-btn-primary" id="mhReturnLink" href="/hub/equity/benefactors.php" style="text-decoration:none; display:inline-block;">Return to Claim</a>
                    </div>
                </div>
            </div>

            <div class="mh-card">
                <video id="mhVideo" playsinline autoplay muted></video>
                <div class="mh-muted" style="margin-top: 10px;">On iOS Safari, recording support varies. Android Chrome is recommended.</div>
            </div>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<script>
(() => {
  const camSel = document.getElementById('mhCam');
  const facingSel = document.getElementById('mhFacing');
  const startBtn = document.getElementById('mhStartCam');
  const recBtn = document.getElementById('mhRec');
  const video = document.getElementById('mhVideo');
  const statusEl = document.getElementById('mhStatus');
  const busyEl = document.getElementById('mhBusy');
  const stepsEl = document.getElementById('mhSteps');
  const returnLinkEl = document.getElementById('mhReturnLink');

  let stream = null;
  let recorder = null;
  let chunks = [];
  let busyTimer = null;
  let busyStartedAt = 0;
  let busyLabel = '';
  let returnUrl = '';
  let forcedReturnUrl = '';

  function setStatus(obj) {
    try {
      statusEl.textContent = typeof obj === 'string' ? obj : JSON.stringify(obj, null, 2);
    } catch {
      statusEl.textContent = String(obj);
    }
  }

  function sanitizeReturnUrl(url) {
    const u = String(url || '').trim();
    if (!u) return '';
    if (u.startsWith('http://') || u.startsWith('https://')) return '';
    if (u.startsWith('//')) return '';
    if (!u.startsWith('/')) return '';
    return u;
  }

  function ensureSteps() {
    if (!stepsEl) return;
    if (stepsEl.childElementCount > 0) return;
    const defs = [
      { key: 'camera', title: 'Camera' },
      { key: 'recording', title: 'Recording' },
      { key: 'upload', title: 'Upload' },
      { key: 'submit', title: 'Submit' },
      { key: 'verify', title: 'Verify' }
    ];
    for (const d of defs) {
      const box = document.createElement('div');
      box.className = 'mh-step';
      box.dataset.step = d.key;
      box.innerHTML = `<div class="mh-step-row"><div class="mh-step-title">${d.title}</div><div class="mh-step-state" data-step-state>WAITING</div></div><div class="mh-step-sub" data-step-sub></div>`;
      stepsEl.appendChild(box);
    }
  }

  function setStep(key, state, message) {
    ensureSteps();
    if (!stepsEl) return;
    const el = stepsEl.querySelector(`[data-step="${CSS.escape(String(key))}"]`);
    if (!el) return;
    el.classList.remove('ok', 'err', 'working');
    const badge = el.querySelector('[data-step-state]');
    const sub = el.querySelector('[data-step-sub]');
    let label = 'WAITING';
    if (state === 'working') { el.classList.add('working'); label = 'IN PROCESS'; }
    if (state === 'ok') { el.classList.add('ok'); label = 'DONE'; }
    if (state === 'err') { el.classList.add('err'); label = 'FAILED'; }
    if (state === 'note') { label = 'INFO'; }
    if (badge) badge.textContent = label;
    if (sub) sub.textContent = message ? String(message) : '';
  }

  function setReturnLink(url) {
    returnUrl = sanitizeReturnUrl(url);
    if (!returnLinkEl) return;
    if (returnUrl) {
      returnLinkEl.href = returnUrl;
      returnLinkEl.style.display = 'inline-block';
    } else {
      returnLinkEl.style.display = 'none';
    }
  }

  function scheduleReturnIfPossible(label) {
    const to = forcedReturnUrl || returnUrl;
    if (!to) return;
    let left = 10;
    if (busyEl) {
      busyEl.style.display = 'block';
      busyEl.textContent = (label ? (String(label) + ' · ') : '') + 'Returning to claim in ' + left + 's…';
    }
    const tick = setInterval(() => {
      left = Math.max(0, left - 1);
      if (busyEl) busyEl.textContent = (label ? (String(label) + ' · ') : '') + 'Returning to claim in ' + left + 's…';
      if (left <= 0) {
        clearInterval(tick);
        try { window.location.href = to; } catch {}
      }
    }, 1000);
    setTimeout(() => {
      try { clearInterval(tick); } catch {}
      try { window.location.href = to; } catch {}
    }, 10000);
    if (busyEl) {
      busyEl.style.display = 'block';
      busyEl.textContent = (label ? (String(label) + ' · ') : '') + 'Returning to claim in 10s…';
    }
  }

  function setBusy(label) {
    busyLabel = String(label || '').trim();
    busyStartedAt = Date.now();
    if (busyEl) {
      busyEl.style.display = 'block';
      busyEl.textContent = 'In process…';
    }
    if (busyTimer) {
      clearInterval(busyTimer);
      busyTimer = null;
    }
    busyTimer = setInterval(() => {
      const s = Math.max(0, Math.floor((Date.now() - busyStartedAt) / 1000));
      const tail = busyLabel ? (' ' + busyLabel) : '';
      if (busyEl) busyEl.textContent = 'In process' + tail + ' (' + s + 's)';
    }, 1000);
  }

  function clearBusy() {
    if (busyTimer) {
      clearInterval(busyTimer);
      busyTimer = null;
    }
    if (busyEl) {
      busyEl.style.display = 'none';
      busyEl.textContent = '';
    }
    busyStartedAt = 0;
    busyLabel = '';
  }

  function showPostActions(data) {
    const box = document.getElementById('mhPostActions');
    if (!box) return;
    box.style.display = 'block';
    const out = document.getElementById('mhResultSummary');
    if (!out) return;
    if (typeof data === 'string') {
      out.textContent = data;
      return;
    }
    const obj = (data && typeof data === 'object') ? data : {};
    const submit = (obj.submit && typeof obj.submit === 'object') ? obj.submit : null;
    const verify = (obj.verify && typeof obj.verify === 'object') ? obj.verify : null;
    const st = String((verify && verify.status) || (submit && submit.status) || obj.status || '');
    const reason = String((verify && verify.reason) || (submit && submit.reason) || obj.reason || '');
    const sid = String(obj.session_id || (verify && verify.session_id) || '');
    const note = String(obj.note || '');
    const stNorm = st ? st.toLowerCase() : '';
    const showReason = !!reason && (stNorm !== 'verified');
    const items = [];
    if (st) items.push({ k: 'Status', v: stNorm === 'verified' ? 'VERIFIED' : st.toUpperCase() });
    if (showReason) items.push({ k: 'Reason', v: reason });
    if (sid) items.push({ k: 'Session ID', v: sid });
    if (note) items.push({ k: 'Note', v: note });

    out.innerHTML = '';
    if (items.length === 0) {
      out.textContent = 'Done';
      return;
    }
    for (const it of items) {
      const row = document.createElement('div');
      row.className = 'mh-summary-row';
      const k = document.createElement('div');
      k.className = 'mh-summary-k';
      k.textContent = String(it.k || '');
      const v = document.createElement('div');
      v.className = 'mh-summary-v';
      v.textContent = String(it.v || '');
      row.appendChild(k);
      row.appendChild(v);
      out.appendChild(row);
    }
  }

  async function listCameras() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
    const devices = await navigator.mediaDevices.enumerateDevices();
    const cams = devices.filter(d => d && d.kind === 'videoinput');
    camSel.innerHTML = '';
    for (const c of cams) {
      const opt = document.createElement('option');
      opt.value = c.deviceId;
      opt.textContent = c.label || ('camera:' + String(c.deviceId || '').slice(0, 6));
      camSel.appendChild(opt);
    }
  }

  async function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('camera_unsupported');
    if (stream) {
      for (const t of stream.getTracks()) { try { t.stop(); } catch {} }
      stream = null;
    }
    const deviceId = String(camSel.value || '');
    const facingMode = String(facingSel.value || 'user');
    const videoConstraints = deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: facingMode } };
    stream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints, audio: false });
    video.srcObject = stream;
    recBtn.disabled = false;
    await listCameras();
    setStatus('Camera ready');
  }

  function stopCamera() {
    if (recorder && recorder.state === 'recording') {
      try { recorder.stop(); } catch {}
    }
    if (stream) {
      for (const t of stream.getTracks()) { try { t.stop(); } catch {} }
      stream = null;
    }
    try { video.srcObject = null; } catch {}
    recBtn.disabled = true;
    setStep('camera', 'note', 'Camera stopped');
    setStatus('Camera stopped');
  }

  const urlParams = new URLSearchParams(window.location.search || '');
  const roomIdParam = String(urlParams.get('room_id') || '');
  const defaultKind = roomIdParam ? 'mosip' : 'passport';
  const kindParam = String(urlParams.get('k') || urlParams.get('kind') || defaultKind);
  const tokenParam = String(urlParams.get('t') || urlParams.get('token') || '');
  const sessionIdParam = String(urlParams.get('session_id') || '');
  const returnUrlParam = String(urlParams.get('return_url') || urlParams.get('return') || '');
  const clipSecondsRaw = parseInt(String(urlParams.get('seconds') || urlParams.get('s') || '5'), 10);
  const clipSeconds = Math.max(3, Math.min(10, Number.isFinite(clipSecondsRaw) ? clipSecondsRaw : 5));
  if (recBtn) recBtn.textContent = 'Record your image';

  const techMode = String(urlParams.get('tech') || urlParams.get('debug') || '') === '1';
  const techEl = document.getElementById('mhTechDetails');
  if (techEl) techEl.style.display = techMode ? 'block' : 'none';

  const isRoom = !!roomIdParam;

  const safeRefReturn = (() => {
    try {
      if (!document.referrer) return '';
      const r = new URL(document.referrer);
      if (r.origin !== window.location.origin) return '';
      return sanitizeReturnUrl(r.pathname + r.search + r.hash);
    } catch {
      return '';
    }
  })();

  const claimReturn = '/hub/equity/benefactors.php';
  forcedReturnUrl = claimReturn;
  if (returnLinkEl) returnLinkEl.textContent = 'Return to Claim';
  setReturnLink(claimReturn);

  if (tokenParam) {
    try {
      const clean = new URL(window.location.href);
      clean.searchParams.delete('token');
      clean.searchParams.delete('t');
      history.replaceState({}, '', clean.toString());
    } catch (e) {}
  }

  async function apiCreateSession(kind) {
    const res = await fetch('/auth/id/api.php?action=create_session', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ kind, room_id: roomIdParam || undefined })
    });
    const txt = await res.text();
    const json = JSON.parse(txt);
    if (!json.ok) throw new Error('create_session_failed');
    return json;
  }

  async function apiUploadVideo(token, blob) {
    const fd = new FormData();
    fd.append('name', 'selfie_video.mp4');
    fd.append('file', blob, 'selfie_video.mp4');
    const res = await fetch('/auth/id/api.php?action=upload_evidence', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token },
      body: fd
    });
    const txt = await res.text();
    const json = JSON.parse(txt);
    if (!json.ok) throw new Error('upload_failed');
    return json;
  }

  async function apiSubmit(token, kind) {
    const res = await fetch('/auth/id/api.php?action=submit_result', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ kind, level: 0 })
    });
    const txt = await res.text();
    const json = JSON.parse(txt);
    if (!json.ok) throw new Error('submit_failed');
    return json;
  }

  async function apiVerify(token) {
    const res = await fetch('/auth/id/api.php?action=verify_session', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + token }
    });
    const txt = await res.text();
    let json = null;
    try { json = JSON.parse(txt); } catch { json = { ok: false, error: 'invalid_json', raw: txt }; }
    if (!json.ok) throw new Error(String(json.error || 'verify_failed'));
    return json;
  }

  async function recordAndUpload() {
    if (!stream) await startCamera();
    if (!window.MediaRecorder) throw new Error('mediarecorder_unsupported');

    const kind = kindParam || 'passport';
    const needsNfc = (kind === 'passport' || kind === 'national_id');
    setStep('camera', 'ok', 'Camera ready');
    let token = tokenParam;
    let sess = null;
    if (!token) {
      sess = await apiCreateSession(kind);
      token = String(sess.token || '');
      if (!token) throw new Error('missing_token');
    }

    const mimeCandidates = [
      'video/mp4;codecs=h264',
      'video/mp4',
      'video/webm;codecs=vp9',
      'video/webm;codecs=vp8',
      'video/webm'
    ];
    let mimeType = '';
    for (const m of mimeCandidates) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m)) { mimeType = m; break; }
    }

    chunks = [];
    recorder = new MediaRecorder(stream, mimeType ? { mimeType } : undefined);
    recorder.ondataavailable = (ev) => { if (ev && ev.data && ev.data.size > 0) chunks.push(ev.data); };

    const stopped = new Promise((resolve, reject) => {
      recorder.onstop = () => resolve(true);
      recorder.onerror = () => reject(new Error('record_failed'));
    });

    recorder.start();
    setStep('recording', 'working', 'Recording ' + clipSeconds + ' seconds…');
    setStatus({ step: 'recording', seconds: clipSeconds, mimeType: mimeType || 'auto' });
    await new Promise(r => setTimeout(r, clipSeconds * 1000));
    recorder.stop();
    await stopped;
    setStep('recording', 'ok', 'Recording complete');

    const blob = new Blob(chunks, { type: mimeType || 'video/webm' });
    setBusy('uploading');
    setStep('upload', 'working', 'Uploading video…');
    setStatus({ step: 'uploading', bytes: blob.size, mimeType: blob.type });
    const up = await apiUploadVideo(token, blob);
    setBusy('submitting');
    setStep('upload', 'ok', 'Upload complete');
    setStep('submit', 'working', 'Submitting for verification…');
    const sub = await apiSubmit(token, kind);
    if (needsNfc) {
      const sid = (sess && sess.session_id) ? sess.session_id : sessionIdParam;
      const msg = {
        ok: true,
        upload: up,
        submit: sub,
        note: 'passport/national_id requires NFC evidence (nfc_dump.json + checks.json). This capture tool only uploads the video.',
        session_id: sid
      };
      setStep('submit', 'ok', 'Submitted');
      setStep('verify', 'note', 'NFC evidence required for this kind');
      setStatus(msg);
      showPostActions(msg);
      clearBusy();
      return;
    }
    if (sub && typeof sub === 'object' && sub.status && sub.status !== 'pending' && (Object.prototype.hasOwnProperty.call(sub, 'verified') || Object.prototype.hasOwnProperty.call(sub, 'score'))) {
      const sid = (sess && sess.session_id) ? sess.session_id : sessionIdParam;
      const msg = { ok: true, upload: up, submit: sub, session_id: sid };
      const st = String(sub.status || '');
      const ok = st === 'verified';
      setStep('submit', 'ok', 'Submitted');
      setStep('verify', ok ? 'ok' : 'err', ok ? 'Verified' : ('Verification result: ' + st));
      setStatus(msg);
      showPostActions(msg);
      clearBusy();
      if (ok) scheduleReturnIfPossible('Verified');
      return;
    }
    setBusy('verifying');
    setStep('submit', 'ok', 'Submitted');
    setStep('verify', 'working', 'Verifying…');
    setStatus({ step: 'verifying', upload: up, submit: sub, session_id: (sess && sess.session_id) ? sess.session_id : sessionIdParam });
    try {
      const ver = await apiVerify(token);
      const st = String(ver.status || '');
      const ok = st === 'verified';
      setStep('verify', ok ? 'ok' : 'err', ok ? 'Verified' : ('Verification result: ' + st));
      setStatus({ ok: true, upload: up, submit: sub, verify: ver, session_id: (sess && sess.session_id) ? sess.session_id : sessionIdParam });
      showPostActions({ ok: true, verify: ver, session_id: (sess && sess.session_id) ? sess.session_id : sessionIdParam });
      clearBusy();
      if (ok) scheduleReturnIfPossible('Verified');
    } catch (e) {
      setStep('verify', 'err', 'Verification failed');
      setStatus({ ok: true, upload: up, submit: sub, verify_error: String(e && e.message ? e.message : e), session_id: (sess && sess.session_id) ? sess.session_id : sessionIdParam });
      showPostActions({ ok: false, verify_error: String(e && e.message ? e.message : e), session_id: (sess && sess.session_id) ? sess.session_id : sessionIdParam });
      clearBusy();
    }
  }

  startBtn.addEventListener('click', async () => {
    if (stream) {
      startBtn.textContent = 'Start Camera';
      stopCamera();
      return;
    }
    try {
      startBtn.textContent = 'Stop Camera';
      setStep('camera', 'working', 'Requesting camera permissions…');
      setStatus('Starting camera...');
      await startCamera();
      setStep('camera', 'ok', 'Camera ready');
    } catch (e) {
      startBtn.textContent = 'Start Camera';
      setStep('camera', 'err', String(e && e.message ? e.message : e));
      setStatus({ error: String(e && e.message ? e.message : e) });
    }
  });
  recBtn.addEventListener('click', async () => {
    recBtn.disabled = true;
    try { await recordAndUpload(); } catch (e) { setStatus({ error: String(e && e.message ? e.message : e) }); clearBusy(); }
    recBtn.disabled = false;
  });

  (async () => {
    try {
      ensureSteps();
      setStep('camera', 'note', 'Select a camera, then start');
      await listCameras();
      setStatus('Select a camera and start');
    } catch {
      ensureSteps();
      setStep('camera', 'note', 'Camera permissions required');
      setStatus('Camera permissions required');
    }
  })();
})();
</script>
</body>
</html>
