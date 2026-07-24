async function mhIdeGetJson(url, opts) {
  const res = await fetch(url, opts);
  const text = await res.text();
  try {
    return { ok: res.ok, status: res.status, json: JSON.parse(text) };
  } catch {
    return { ok: false, status: res.status, json: null, raw: text };
  }
}

function mhIdeRandomId(prefix) {
  const rnd = Math.random().toString(16).slice(2);
  const ts = Date.now().toString(16);
  return `${prefix}_${ts}_${rnd}`;
}

function mhIdeAppendMessage(logEl, role, text) {
  const row = document.createElement('div');
  row.className = 'mh-ide-msg';
  const roleEl = document.createElement('div');
  roleEl.className = 'mh-ide-role';
  roleEl.textContent = role;
  const textEl = document.createElement('div');
  textEl.className = 'mh-ide-text';
  textEl.textContent = text || '';
  row.appendChild(roleEl);
  row.appendChild(textEl);
  logEl.appendChild(row);
  logEl.scrollTop = logEl.scrollHeight;
}

function mhIdeSetStatus(msg) {
  const el = document.getElementById('mhInputStatus');
  if (el) el.textContent = msg || '';
}

function mhIdeIsGarbageText(text) {
  const s = String(text || '').trim();
  if (!s) return true;
  const compact = s.replace(/\s+/g, '');
  if (compact.length < 3) return true;
  const letters = (s.match(/[A-Za-z]/g) || []).length;
  if (letters === 0) return true;
  const uniq = new Set(compact.toLowerCase().split('')).size;
  if (uniq <= 3 && compact.length >= 10) return true;
  return false;
}

function mhIdeSpeak(text) {
  if (!window.speechSynthesis || !text) return;
  try {
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(String(text));
    u.rate = 1.03;
    u.pitch = 1.0;
    u.volume = 1.0;
    window.speechSynthesis.speak(u);
  } catch {}
}

function mhIdeSetBusy(b) {
  document.getElementById('mhSend').disabled = b;
  document.getElementById('mhRefresh').disabled = b;
  document.getElementById('mhRemember').disabled = b;
  const v = document.getElementById('mhVoice');
  const vi = document.getElementById('mhVision');
  if (v) v.disabled = b;
  if (vi) vi.disabled = b;
  const jv = document.getElementById('mhJobVoice');
  const jvi = document.getElementById('mhJobVision');
  const jc = document.getElementById('mhJobCreate');
  if (jv) jv.disabled = b;
  if (jvi) jvi.disabled = b;
  if (jc) jc.disabled = b;
}

function mhIdeSetError(msg) {
  const el = document.getElementById('mhError');
  el.textContent = msg || '';
}

let mhIdeCtx = null;
let mhJobAbort = null;
let mhIdeUploads = { images: [] };
let mhJobUploads = { images: [] };
let mhUploadTarget = 'chat';
let mhMicStream = null;
let mhMicRecorder = null;
let mhCameraStream = null;
let mhActiveVoiceBtn = null;
let mhWavCtx = null;
let mhWavSource = null;
let mhWavProcessor = null;
let mhWavStream = null;
let mhWavChunks = [];
let mhWavSampleRate = 48000;
let mhWavStopResolve = null;
let mhWavKeepStream = false;
let mhSelectedMicId = '';
let mhSelectedCamId = '';
let mhAttachFrameOnVoice = true;
let mhAutoSendOnVoice = false;
let mhMeetingActive = false;
let mhMeetingVadTimer = null;
let mhMeetingMotionTimer = null;
let mhMeetingMicStream = null;
let mhMeetingAudioCtx = null;
let mhMeetingAnalyser = null;
let mhMeetingLastSpeechMs = 0;
let mhMeetingLastVisionSendMs = 0;
let mhMeetingLastMotionMs = 0;
let mhMeetingPrevFrame = null;
let mhMeetingLastRecordingEndMs = 0;
let mhMeetingLastMotionScore = 0;

async function mhIdeLoadContext() {
  mhIdeSetError('');
  const data = await mhIdeGetJson('/hub/workbench/api/context.php', { method: 'GET' });
  if (!data.ok || !data.json || !data.json.success) {
    throw new Error('context_unavailable');
  }
  mhIdeCtx = data.json.context || null;

  const ctx = mhIdeCtx || {};
  const tenant = String(ctx.tenant_id || '');
  const user = String(ctx.user_id || '');
  const persona = String(ctx.persona_id || '');

  document.getElementById('mhTenant').textContent = tenant;
  document.getElementById('mhUser').textContent = user;
  document.getElementById('mhPersona').textContent = persona;
  document.getElementById('mhWorkspaceRoot').textContent = String(data.json.workspace_root || '');
  document.getElementById('mhUserPill').textContent = user || String(ctx.username || '');
  document.getElementById('mhPersonaPill').textContent = persona;

  const key = `mh_ide_conversation_${tenant}_${persona}_${user}`;
  let conv = localStorage.getItem(key);
  if (!conv) {
    conv = mhIdeRandomId('ide');
    localStorage.setItem(key, conv);
  }
  return { tenant, user, persona, conversationId: conv };
}

function mhIdeStopJobStream() {
  if (mhJobAbort) {
    mhJobAbort.abort();
    mhJobAbort = null;
  }
  const stopBtn = document.getElementById('mhJobStop');
  if (stopBtn) stopBtn.disabled = true;
}

async function mhIdeStreamSse(url, onEvent) {
  const controller = new AbortController();
  const res = await fetch(url, {
    method: 'GET',
    headers: { Accept: 'text/event-stream' },
    signal: controller.signal
  });
  if (!res.ok || !res.body) {
    throw new Error(`sse_failed_${res.status}`);
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buf = '';

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buf += decoder.decode(value, { stream: true });

    while (true) {
      const idx = buf.indexOf('\n\n');
      if (idx === -1) break;
      const rawEvent = buf.slice(0, idx);
      buf = buf.slice(idx + 2);

      const lines = rawEvent.split('\n');
      let evType = '';
      let evId = '';
      const dataLines = [];
      for (const line of lines) {
        if (line.startsWith('event:')) evType = line.slice(6).trim();
        else if (line.startsWith('id:')) evId = line.slice(3).trim();
        else if (line.startsWith('data:')) dataLines.push(line.slice(5).trimStart());
      }
      const dataStr = dataLines.join('\n');
      let dataJson = null;
      if (dataStr) {
        try {
          dataJson = JSON.parse(dataStr);
        } catch {
          dataJson = null;
        }
      }
      onEvent({
        type: evType || 'message',
        id: evId,
        data: dataJson,
        raw: dataStr
      });
    }
  }

  return controller;
}

async function mhIdeCreateJob(goalText, repoUrl, repoCommit) {
  if (!mhIdeCtx) await mhIdeLoadContext();

  const ctx = mhIdeCtx || {};
  const tenant_id = String(ctx.tenant_id || '');
  const persona_id = String(ctx.persona_id || '');
  const user_id = String(ctx.user_id || '');

  if (!tenant_id || !persona_id || !user_id) {
    throw new Error('identity_missing');
  }

  const payload = {
    input: { text: goalText, images: mhJobUploads.images.slice(0, 6) },
    task_type: 'code',
    route_hint: 'auto',
    vision_mode: 'auto',
    channel: 'ide',
    conversation_id: mhIdeRandomId('jobconv'),
    request_id: mhIdeRandomId('jobreq')
  };

  if (repoUrl) {
    payload.hide = {
      repository: {
        url: repoUrl,
        commit: repoCommit || undefined
      }
    };
  }

  const data = await mhIdeGetJson('/hub/workbench/api/agent/jobs/', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  if (!data.ok || !data.json) {
    throw new Error('job_create_failed');
  }
  if (data.json.ok !== true) {
    throw new Error(data.json.detail || data.json.error || 'job_create_failed');
  }
  return data.json;
}

async function mhIdeSendChat(text) {
  if (!mhIdeCtx) await mhIdeLoadContext();

  const ctx = mhIdeCtx || {};
  const tenant_id = String(ctx.tenant_id || '');
  const persona_id = String(ctx.persona_id || '');
  const user_id = String(ctx.user_id || '');

  if (!tenant_id || !persona_id || !user_id) {
    throw new Error('identity_missing');
  }

  const key = `mh_ide_conversation_${tenant_id}_${persona_id}_${user_id}`;
  const conversation_id = localStorage.getItem(key) || mhIdeRandomId('ide');
  localStorage.setItem(key, conversation_id);

  const payload = {
    tenant_id,
    persona_id,
    user_id,
    channel: 'ide',
    conversation_id,
    request_id: mhIdeRandomId('req'),
    input: { text, images: mhIdeUploads.images.slice(0, 6) }
  };

  const data = await mhIdeGetJson('/v1/meta-human/respond.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  if (!data.ok || !data.json || !data.json.ok) {
    const err = data.json && data.json.error ? String(data.json.error) : 'respond_failed';
    const us = data.json && typeof data.json.upstream_status !== 'undefined' ? ` upstream_status=${data.json.upstream_status}` : '';
    const ue = data.json && data.json.upstream_error ? ` upstream_error=${data.json.upstream_error}` : '';
    throw new Error(err + us + ue);
  }

  const mem = data.json.memory || {};
  document.getElementById('mhMemoryStats').textContent = `memory: semantic=${Number(mem.semantic_hits || 0)}, graph=${Number(mem.graph_items || 0)}`;

  const result = data.json.result || {};
  const inner = result.result || {};
  const content = inner.choices && inner.choices[0] && inner.choices[0].message ? inner.choices[0].message.content : '';
  mhIdeUploads.images = [];
  return String(content || '');
}

async function mhIdeUpload(kind, file) {
  const fd = new FormData();
  fd.append('kind', kind);
  fd.append('file', file);
  const res = await fetch('/hub/workbench/api/inbox.php', { method: 'POST', body: fd });
  const text = await res.text();
  try {
    return { ok: res.ok, json: JSON.parse(text) };
  } catch {
    return { ok: false, json: null, raw: text };
  }
}

async function mhIdeTranscribeInboxAudio(id) {
  const data = await mhIdeGetJson('/hub/workbench/api/persona-io/transcribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ kind: 'audio', id })
  });
  if (!data.ok || !data.json || !data.json.success) {
    const lane = data.json && data.json.lane ? ` lane=${data.json.lane}` : '';
    const err = data.json && data.json.error ? ` error=${data.json.error}` : '';
    throw new Error(`transcribe_failed status=${data.status}${lane}${err}`);
  }
  return String(data.json.text || '');
}

async function mhIdeEnsureMicStream() {
  if (mhMicStream) return mhMicStream;
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    throw new Error('mic_unsupported');
  }
  const audio = mhSelectedMicId ? { deviceId: { exact: mhSelectedMicId } } : true;
  mhMicStream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
  return mhMicStream;
}

function mhIdeStopMic() {
  if (mhMicRecorder) {
    try { mhMicRecorder.stop(); } catch {}
    mhMicRecorder = null;
  }
  if (mhMicStream) {
    for (const t of mhMicStream.getTracks()) {
      try { t.stop(); } catch {}
    }
    mhMicStream = null;
  }
}

function mhIdeStopWavRecorder() {
  if (mhWavProcessor) {
    try { mhWavProcessor.disconnect(); } catch {}
    mhWavProcessor = null;
  }
  if (mhWavSource) {
    try { mhWavSource.disconnect(); } catch {}
    mhWavSource = null;
  }
  if (mhWavCtx) {
    try { mhWavCtx.close(); } catch {}
    mhWavCtx = null;
  }
  if (mhWavStream && !mhWavKeepStream) {
    for (const t of mhWavStream.getTracks()) {
      try { t.stop(); } catch {}
    }
    mhWavStream = null;
  }
  mhWavKeepStream = false;
  if (mhWavStopResolve) {
    const r = mhWavStopResolve;
    mhWavStopResolve = null;
    r();
  }
}

function mhIdeDownsampleTo16k(float32, srcRate) {
  const dstRate = 16000;
  if (!float32 || float32.length === 0) return new Float32Array(0);
  if (srcRate === dstRate) return float32;
  const ratio = srcRate / dstRate;
  const newLen = Math.max(1, Math.floor(float32.length / ratio));
  const out = new Float32Array(newLen);
  let offsetResult = 0;
  let offsetBuffer = 0;
  while (offsetResult < out.length) {
    const nextOffsetBuffer = Math.min(float32.length, Math.round((offsetResult + 1) * ratio));
    let sum = 0;
    let count = 0;
    for (let i = offsetBuffer; i < nextOffsetBuffer; i++) {
      sum += float32[i];
      count++;
    }
    out[offsetResult] = count ? sum / count : 0;
    offsetResult++;
    offsetBuffer = nextOffsetBuffer;
  }
  return out;
}

function mhIdeEncodeWavPcm16Mono(float32, sampleRate) {
  const dataLen = float32.length * 2;
  const buffer = new ArrayBuffer(44 + dataLen);
  const view = new DataView(buffer);
  let o = 0;
  function wStr(s) { for (let i = 0; i < s.length; i++) view.setUint8(o++, s.charCodeAt(i)); }
  function w32(v) { view.setUint32(o, v, true); o += 4; }
  function w16(v) { view.setUint16(o, v, true); o += 2; }
  wStr('RIFF'); w32(36 + dataLen); wStr('WAVE');
  wStr('fmt '); w32(16); w16(1); w16(1); w32(sampleRate); w32(sampleRate * 2); w16(2); w16(16);
  wStr('data'); w32(dataLen);
  for (let i = 0; i < float32.length; i++) {
    const s = Math.max(-1, Math.min(1, float32[i]));
    view.setInt16(o, s < 0 ? s * 0x8000 : s * 0x7fff, true);
    o += 2;
  }
  return buffer;
}

async function mhIdeStartWavRecorder(target, stream, keepStream) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    throw new Error('mic_unsupported');
  }
  mhWavKeepStream = keepStream === true;
  if (stream) {
    mhWavStream = stream;
  } else {
    const audio = mhSelectedMicId ? { deviceId: { exact: mhSelectedMicId } } : true;
    mhWavStream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
  }
  mhWavCtx = new (window.AudioContext || window['webkitAudioContext'])();
  if (mhWavCtx && mhWavCtx.state === 'suspended') {
    try { await mhWavCtx.resume(); } catch {}
  }
  mhWavSampleRate = mhWavCtx.sampleRate || 48000;
  mhWavChunks = [];
  mhWavSource = mhWavCtx.createMediaStreamSource(mhWavStream);
  mhWavProcessor = mhWavCtx.createScriptProcessor(4096, 1, 1);
  mhWavProcessor.onaudioprocess = (e) => {
    if (!e.inputBuffer) return;
    const data = e.inputBuffer.getChannelData(0);
    mhWavChunks.push(new Float32Array(data));
  };
  mhWavSource.connect(mhWavProcessor);
  const gain = mhWavCtx.createGain();
  gain.gain.value = 0;
  mhWavProcessor.connect(gain);
  gain.connect(mhWavCtx.destination);
  const stopped = new Promise((resolve) => { mhWavStopResolve = resolve; });
  return { stopped, target };
}

async function mhIdeStartMeetingStreams() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('media_unsupported');
  if (!mhMeetingMicStream) {
    const audio = mhSelectedMicId ? { deviceId: { exact: mhSelectedMicId } } : true;
    mhMeetingMicStream = await navigator.mediaDevices.getUserMedia({ audio, video: false });
  }
  if (!mhMeetingAudioCtx) {
    mhMeetingAudioCtx = new (window.AudioContext || window['webkitAudioContext'])();
    if (mhMeetingAudioCtx && mhMeetingAudioCtx.state === 'suspended') {
      try { await mhMeetingAudioCtx.resume(); } catch {}
    }
    mhMeetingAnalyser = mhMeetingAudioCtx.createAnalyser();
    mhMeetingAnalyser.fftSize = 1024;
    const src = mhMeetingAudioCtx.createMediaStreamSource(mhMeetingMicStream);
    src.connect(mhMeetingAnalyser);
  }
}

function mhIdeStopMeetingStreams() {
  if (mhMeetingVadTimer) {
    clearInterval(mhMeetingVadTimer);
    mhMeetingVadTimer = null;
  }
  if (mhMeetingMotionTimer) {
    clearInterval(mhMeetingMotionTimer);
    mhMeetingMotionTimer = null;
  }
  if (mhMeetingAudioCtx) {
    try { mhMeetingAudioCtx.close(); } catch {}
    mhMeetingAudioCtx = null;
    mhMeetingAnalyser = null;
  }
  if (mhMeetingMicStream) {
    for (const t of mhMeetingMicStream.getTracks()) {
      try { t.stop(); } catch {}
    }
    mhMeetingMicStream = null;
  }
  mhMeetingPrevFrame = null;
}

function mhIdeMotionScoreFromVideo(videoEl, prev) {
  const w = 160;
  const h = 90;
  if (!videoEl || !videoEl.videoWidth) return { score: 0, next: prev };
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  if (!ctx) return { score: 0, next: prev };
  ctx.drawImage(videoEl, 0, 0, w, h);
  const img = ctx.getImageData(0, 0, w, h).data;
  const len = w * h;
  const cur = new Uint8Array(len);
  for (let i = 0; i < len; i++) {
    const r = img[i * 4];
    const g = img[i * 4 + 1];
    const b = img[i * 4 + 2];
    cur[i] = (r * 0.299 + g * 0.587 + b * 0.114) | 0;
  }
  if (!prev) return { score: 0, next: cur };
  let sum = 0;
  for (let i = 0; i < len; i++) sum += Math.abs(cur[i] - prev[i]);
  const score = sum / len;
  return { score, next: cur };
}

function mhIdeBuildWavBlob() {
  const total = mhWavChunks.reduce((n, a) => n + a.length, 0);
  const merged = new Float32Array(total);
  let off = 0;
  for (const a of mhWavChunks) {
    merged.set(a, off);
    off += a.length;
  }
  const ds = mhIdeDownsampleTo16k(merged, mhWavSampleRate);
  const wavBuf = mhIdeEncodeWavPcm16Mono(ds, 16000);
  return new Blob([wavBuf], { type: 'audio/wav' });
}

async function mhIdeRecordOnce(target) {
  const stream = await mhIdeEnsureMicStream();
  const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
  const rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
  mhMicRecorder = rec;
  const chunks = [];
  rec.addEventListener('dataavailable', (e) => {
    if (e.data && e.data.size > 0) chunks.push(e.data);
  });
  const done = new Promise((resolve, reject) => {
    rec.addEventListener('stop', resolve);
    rec.addEventListener('error', reject);
  });
  rec.start();
  return { rec, chunks, done, target };
}

async function mhIdeUploadAndTranscribeBlob(blob) {
  const ext = blob.type && blob.type.includes('ogg') ? 'ogg' : (blob.type && blob.type.includes('wav') ? 'wav' : 'webm');
  const file = new File([blob], `voice.${ext}`, { type: blob.type || 'application/octet-stream' });
  const r = await mhIdeUpload('audio', file);
  const id = r && r.json && r.json.success ? String(r.json.id || '') : '';
  if (!id) throw new Error('upload_failed');
  return await mhIdeTranscribeInboxAudio(id);
}

async function mhIdeOpenCameraOverlay(target) {
  const overlay = document.getElementById('mhCaptureOverlay');
  const video = document.getElementById('mhCameraPreview');
  const hint = document.getElementById('mhCaptureHint');
  const camSel = document.getElementById('mhCameraDevice');
  if (!overlay || !video) throw new Error('overlay_missing');
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('camera_unsupported');
  if (mhCameraStream) {
    for (const t of mhCameraStream.getTracks()) {
      try { t.stop(); } catch {}
    }
    mhCameraStream = null;
  }
  const videoConstraint = mhSelectedCamId
    ? { deviceId: { exact: mhSelectedCamId } }
    : { facingMode: 'user' };
  mhCameraStream = await navigator.mediaDevices.getUserMedia({ video: videoConstraint, audio: false });
  video.srcObject = mhCameraStream;
  overlay.dataset.target = target;
  if (hint) hint.textContent = target === 'job' ? 'Capture will attach to the next job request.' : 'Capture will attach to the next message.';
  overlay.classList.add('is-open');
  overlay.setAttribute('aria-hidden', 'false');
  if (camSel && mhSelectedCamId) camSel.value = mhSelectedCamId;
  try { await mhIdeLoadDeviceLists(); } catch {}
}

function mhIdeCloseCameraOverlay() {
  const overlay = document.getElementById('mhCaptureOverlay');
  const video = document.getElementById('mhCameraPreview');
  if (overlay) {
    overlay.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
  }
  if (video) video.srcObject = null;
  if (mhCameraStream) {
    for (const t of mhCameraStream.getTracks()) {
      try { t.stop(); } catch {}
    }
    mhCameraStream = null;
  }
}

async function mhIdeCaptureCameraFrame(targetOverride) {
  const overlay = document.getElementById('mhCaptureOverlay');
  const video = document.getElementById('mhCameraPreview');
  if (!overlay || !video) throw new Error('overlay_missing');
  const target = targetOverride || overlay.dataset.target || 'chat';
  const w = Math.max(1, video.videoWidth || 1280);
  const h = Math.max(1, video.videoHeight || 720);
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  if (!ctx) throw new Error('canvas_failed');
  ctx.drawImage(video, 0, 0, w, h);
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.92));
  if (!blob) throw new Error('capture_failed');
  const file = new File([blob], 'camera.jpg', { type: 'image/jpeg' });
  const r = await mhIdeUpload('image', file);
  const url = r && r.json && r.json.success && r.json.stored && r.json.stored.url ? String(r.json.stored.url) : '';
  if (!url) throw new Error('upload_failed');
  if (target === 'job') mhJobUploads.images.push(url);
  else mhIdeUploads.images.push(url);
  return { target, url };
}

async function mhIdeLoadDeviceLists() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
  const devices = await navigator.mediaDevices.enumerateDevices();
  const cams = devices.filter(d => d.kind === 'videoinput');
  const mics = devices.filter(d => d.kind === 'audioinput');
  const camSel = document.getElementById('mhCameraDevice');
  const micSel = document.getElementById('mhMicDevice');
  if (camSel) {
    camSel.innerHTML = '';
    for (const d of cams) {
      const opt = document.createElement('option');
      opt.value = d.deviceId;
      opt.textContent = d.label || `camera:${d.deviceId.slice(0, 6)}`;
      camSel.appendChild(opt);
    }
    if (!mhSelectedCamId && cams[0]) mhSelectedCamId = cams[0].deviceId;
    if (mhSelectedCamId) camSel.value = mhSelectedCamId;
  }
  if (micSel) {
    micSel.innerHTML = '';
    for (const d of mics) {
      const opt = document.createElement('option');
      opt.value = d.deviceId;
      opt.textContent = d.label || `mic:${d.deviceId.slice(0, 6)}`;
      micSel.appendChild(opt);
    }
    if (!mhSelectedMicId && mics[0]) mhSelectedMicId = mics[0].deviceId;
    if (mhSelectedMicId) micSel.value = mhSelectedMicId;
  }
}

async function mhIdeRemember(text) {
  const payload = {
    text,
    kind: 'note',
    source: 'ide',
    tags: ['manual']
  };
  const data = await mhIdeGetJson('/hub/workbench/api/memory_ingest.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  if (!data.ok || !data.json || !data.json.success) {
    throw new Error('memory_ingest_failed');
  }
  return data.json;
}

async function mhIdeMain() {
  const logEl = document.getElementById('mhChatLog');
  const inputEl = document.getElementById('mhChatInput');
  const sendBtn = document.getElementById('mhSend');
  const refreshBtn = document.getElementById('mhRefresh');
  const rememberBtn = document.getElementById('mhRemember');
  const voiceBtn = document.getElementById('mhVoice');
  const visionBtn = document.getElementById('mhVision');
  const voiceFile = document.getElementById('mhVoiceFile');
  const visionFile = document.getElementById('mhVisionFile');
  const cameraCaptureBtn = document.getElementById('mhCameraCapture');
  const cameraCloseBtn = document.getElementById('mhCameraClose');
  const camSel = document.getElementById('mhCameraDevice');
  const micSel = document.getElementById('mhMicDevice');
  const attachOnVoiceEl = document.getElementById('mhAttachFrameOnVoice');
  const autoSendEl = document.getElementById('mhAutoSendOnVoice');
  const jobGoalEl = document.getElementById('mhJobGoal');
  const jobRepoUrlEl = document.getElementById('mhJobRepoUrl');
  const jobRepoCommitEl = document.getElementById('mhJobRepoCommit');
  const jobCreateBtn = document.getElementById('mhJobCreate');
  const jobVoiceBtn = document.getElementById('mhJobVoice');
  const jobVisionBtn = document.getElementById('mhJobVision');
  const jobStopBtn = document.getElementById('mhJobStop');
  const jobMetaEl = document.getElementById('mhJobMeta');
  const jobOutEl = document.getElementById('mhJobOut');
  const meetRoomIdEl = document.getElementById('mhMeetRoomId');
  const meetBotNameEl = document.getElementById('mhMeetBotName');
  const meetStartBtn = document.getElementById('mhMeetStart');
  const meetStopBtn = document.getElementById('mhMeetStop');
  const meetStatusBtn = document.getElementById('mhMeetStatus');
  const meetOutEl = document.getElementById('mhMeetOut');


  mhIdeSetBusy(true);
  try {
    await mhIdeLoadContext();
    mhIdeAppendMessage(logEl, 'system', 'IDE context loaded. Chat is ready.');
    const savedRepo = localStorage.getItem('mh_ide_job_repo_url') || '';
    const savedCommit = localStorage.getItem('mh_ide_job_repo_commit') || '';
    if (jobRepoUrlEl && !jobRepoUrlEl.value && savedRepo) jobRepoUrlEl.value = savedRepo;
    if (jobRepoCommitEl && !jobRepoCommitEl.value && savedCommit) jobRepoCommitEl.value = savedCommit;
    mhAttachFrameOnVoice = attachOnVoiceEl ? !!attachOnVoiceEl.checked : true;
    mhAutoSendOnVoice = autoSendEl ? !!autoSendEl.checked : false;
    await mhIdeLoadDeviceLists();
    const savedRoom = localStorage.getItem('mh_ide_meet_room_id') || '';
    const savedBot = localStorage.getItem('mh_ide_meet_bot_name') || '';
    if (meetRoomIdEl && !meetRoomIdEl.value && savedRoom) meetRoomIdEl.value = savedRoom;
    if (meetBotNameEl && !meetBotNameEl.value) meetBotNameEl.value = savedBot || `Meta Human ${String(mhIdeCtx.persona_id || '').trim() || 'Persona'}`;
  } catch (e) {
    mhIdeSetError('Failed to load context. Open Workbench to verify login/persona selection.');
  } finally {
    mhIdeSetBusy(false);
  }

  async function mhIdeMeetCall(action, payload) {
    const url = `/hub/workbench/api/meet/bot.php?action=${encodeURIComponent(action)}`;
    if (action === 'status') {
      return await mhIdeGetJson(url);
    }
    return await mhIdeGetJson(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    });
  }

  async function mhIdeMeetRefresh() {
    if (!meetOutEl) return;
    const r = await mhIdeMeetCall('status');
    if (!r.ok || !r.json || !r.json.ok) throw new Error(r.json && r.json.error ? r.json.error : 'meet_status_failed');
    meetOutEl.textContent = JSON.stringify(r.json.data, null, 2);
  }

  if (meetStatusBtn) {
    meetStatusBtn.addEventListener('click', async () => {
      mhIdeSetError('');
      mhIdeSetStatus('Checking meeting bot status…');
      try {
        await mhIdeMeetRefresh();
      } catch (e) {
        mhIdeSetError(`Meeting bot status failed: ${e && e.message ? e.message : 'unknown_error'}`);
      } finally {
        mhIdeSetStatus('');
      }
    });
  }

  if (meetStartBtn) {
    meetStartBtn.addEventListener('click', async () => {
      const roomId = meetRoomIdEl ? String(meetRoomIdEl.value || '').trim() : '';
      const botName = meetBotNameEl ? String(meetBotNameEl.value || '').trim() : '';
      if (!roomId) return;
      mhIdeSetError('');
      mhIdeSetStatus('Starting meeting bot…');
      try {
        localStorage.setItem('mh_ide_meet_room_id', roomId);
        if (botName) localStorage.setItem('mh_ide_meet_bot_name', botName);
        const r = await mhIdeMeetCall('start', { room_id: roomId, bot_name: botName });
        if (!r.ok || !r.json || !r.json.ok) throw new Error(r.json && r.json.error ? r.json.error : 'meet_start_failed');
        if (meetOutEl) meetOutEl.textContent = JSON.stringify(r.json.data, null, 2);
      } catch (e) {
        mhIdeSetError(`Meeting bot start failed: ${e && e.message ? e.message : 'unknown_error'}`);
      } finally {
        mhIdeSetStatus('');
      }
    });
  }

  if (meetStopBtn) {
    meetStopBtn.addEventListener('click', async () => {
      const roomId = meetRoomIdEl ? String(meetRoomIdEl.value || '').trim() : '';
      if (!roomId) return;
      mhIdeSetError('');
      mhIdeSetStatus('Stopping meeting bot…');
      try {
        const r = await mhIdeMeetCall('stop', { room_id: roomId });
        if (!r.ok || !r.json || !r.json.ok) throw new Error(r.json && r.json.error ? r.json.error : 'meet_stop_failed');
        if (meetOutEl) meetOutEl.textContent = JSON.stringify(r.json.data, null, 2);
      } catch (e) {
        mhIdeSetError(`Meeting bot stop failed: ${e && e.message ? e.message : 'unknown_error'}`);
      } finally {
        mhIdeSetStatus('');
      }
    });
  }

  if (camSel) {
    camSel.addEventListener('change', async () => {
      mhSelectedCamId = String(camSel.value || '');
      if (document.getElementById('mhCaptureOverlay')?.classList.contains('is-open')) {
        const target = document.getElementById('mhCaptureOverlay').dataset.target || 'chat';
        try { await mhIdeOpenCameraOverlay(target); } catch {}
      }
    });
  }
  if (micSel) {
    micSel.addEventListener('change', () => {
      mhSelectedMicId = String(micSel.value || '');
    });
  }
  if (attachOnVoiceEl) attachOnVoiceEl.addEventListener('change', () => { mhAttachFrameOnVoice = !!attachOnVoiceEl.checked; });
  const videoEl = document.getElementById('mhCameraPreview');
  let mhMeetingRecordingStartedMs = 0;
  let mhMeetingSilenceMs = 0;

  async function mhIdeStartMeeting(target) {
    if (mhMeetingActive) return;
    mhMeetingActive = true;
    mhMeetingLastSpeechMs = 0;
    mhMeetingLastVisionSendMs = 0;
    mhMeetingLastMotionMs = 0;
    mhMeetingPrevFrame = null;
    mhMeetingRecordingStartedMs = 0;
    mhMeetingSilenceMs = 0;
    mhIdeAppendMessage(logEl, 'system', 'Meeting mode started.');

    await mhIdeStartMeetingStreams();
    await mhIdeLoadDeviceLists();
    await mhIdeOpenCameraOverlay(target);

    mhMeetingVadTimer = setInterval(() => {
      if (!mhMeetingActive || !mhMeetingAnalyser) return;
      const now = Date.now();
      const buf = new Uint8Array(mhMeetingAnalyser.fftSize);
      mhMeetingAnalyser.getByteTimeDomainData(buf);
      let sum = 0;
      for (let i = 0; i < buf.length; i++) {
        const v = (buf[i] - 128) / 128;
        sum += v * v;
      }
      const rms = Math.sqrt(sum / buf.length);
      const hint = document.getElementById('mhCaptureHint');
      if (hint) hint.textContent = `meeting: rms=${rms.toFixed(3)} motion=${mhMeetingLastMotionScore.toFixed(1)} rec=${mhWavStopResolve ? 'yes' : 'no'}`;
      const startThr = 0.012;
      const contThr = 0.008;
      const stopThr = 0.006;

      if (!mhWavStopResolve) {
        if (rms > startThr && now - mhMeetingLastRecordingEndMs > 1200) {
          mhMeetingRecordingStartedMs = now;
          mhMeetingSilenceMs = 0;
          mhMeetingLastSpeechMs = now;
          mhIdeStartWavRecorder(target, mhMeetingMicStream, true).then(({ stopped }) => {
            (async () => {
              await stopped;
              const endNow = Date.now();
              mhMeetingLastRecordingEndMs = endNow;
              const blob = mhIdeBuildWavBlob();
              let tx = '';
              try { tx = await mhIdeUploadAndTranscribeBlob(blob); } catch { tx = ''; }
              let captured = null;
              if (mhAttachFrameOnVoice && mhCameraStream && (endNow - mhMeetingLastMotionMs) < 2500) {
                try { captured = await mhIdeCaptureCameraFrame(target); } catch {}
              }
              const msgText = (tx || '').trim();
              if (msgText !== '') {
                mhIdeAppendMessage(logEl, 'user', msgText);
                try {
                  const reply = await mhIdeSendChat(msgText);
                  mhIdeAppendMessage(logEl, 'assistant', reply || '(no content)');
                  if (reply) mhIdeSpeak(reply);
                } catch (e) {
                  mhIdeSetError(`Chat failed: ${e && e.message ? e.message : 'unknown_error'}`);
                }
              } else if (captured && captured.url && now - mhMeetingLastVisionSendMs > 4000) {
                mhMeetingLastVisionSendMs = Date.now();
                mhIdeAppendMessage(logEl, 'user', 'Vision update.');
                try {
                  const reply = await mhIdeSendChat('Vision update.');
                  mhIdeAppendMessage(logEl, 'assistant', reply || '(no content)');
                  if (reply) mhIdeSpeak(reply);
                } catch (e) {
                  mhIdeSetError(`Chat failed: ${e && e.message ? e.message : 'unknown_error'}`);
                }
              }
            })();
          }).catch(() => {});
        }
        return;
      }

      if (rms > contThr) {
        mhMeetingLastSpeechMs = now;
        mhMeetingSilenceMs = 0;
      } else if (rms < stopThr) {
        mhMeetingSilenceMs += 80;
      }

      if (mhMeetingRecordingStartedMs && (now - mhMeetingRecordingStartedMs) > 800 && mhMeetingSilenceMs > 900) {
        mhIdeStopWavRecorder();
      }
    }, 80);

    mhMeetingMotionTimer = setInterval(() => {
      if (!mhMeetingActive) return;
      if (!videoEl || !mhCameraStream) return;
      const now = Date.now();
      const r = mhIdeMotionScoreFromVideo(videoEl, mhMeetingPrevFrame);
      mhMeetingPrevFrame = r.next;
      mhMeetingLastMotionScore = r.score;
      if (r.score > 12) {
        mhMeetingLastMotionMs = now;
      }
      if (mhAutoSendOnVoice && r.score > 10 && now - mhMeetingLastVisionSendMs > 8000 && !mhWavStopResolve) {
        mhMeetingLastVisionSendMs = now;
        (async () => {
          try {
            const cap = await mhIdeCaptureCameraFrame(target);
            if (cap && cap.url) {
              mhIdeAppendMessage(logEl, 'user', 'Vision update.');
              const reply = await mhIdeSendChat('Vision update.');
              mhIdeAppendMessage(logEl, 'assistant', reply || '(no content)');
              if (reply) mhIdeSpeak(reply);
            }
          } catch (e) {
            mhIdeSetError('Vision update failed.');
          }
        })();
      }
    }, 500);
  }

  function mhIdeStopMeeting() {
    if (!mhMeetingActive) return;
    mhMeetingActive = false;
    mhIdeStopWavRecorder();
    mhIdeStopMeetingStreams();
    mhIdeAppendMessage(logEl, 'system', 'Meeting mode stopped.');
  }

  if (autoSendEl) autoSendEl.addEventListener('change', async () => {
    mhAutoSendOnVoice = !!autoSendEl.checked;
    if (mhAutoSendOnVoice) {
      try {
        await mhIdeStartMeeting('chat');
      } catch (e) {
        mhAutoSendOnVoice = false;
        autoSendEl.checked = false;
        mhIdeSetError('Meeting mode failed to start. Check mic/camera permissions.');
      }
    } else {
      mhIdeStopMeeting();
    }
  });

  refreshBtn.addEventListener('click', async () => {
    mhIdeSetBusy(true);
    mhIdeSetError('');
    try {
      await mhIdeLoadContext();
      mhIdeAppendMessage(logEl, 'system', 'Context refreshed.');
    } catch (e) {
      mhIdeSetError('Failed to refresh context.');
    } finally {
      mhIdeSetBusy(false);
    }
  });

  rememberBtn.addEventListener('click', async () => {
    mhIdeSetBusy(true);
    mhIdeSetError('');
    try {
      const text = (inputEl.value || '').trim();
      if (!text) {
        mhIdeSetError('Nothing to remember.');
      } else {
        await mhIdeRemember(text);
        mhIdeAppendMessage(logEl, 'system', 'Saved to memory.');
      }
    } catch (e) {
      mhIdeSetError('Memory ingest failed.');
    } finally {
      mhIdeSetBusy(false);
    }
  });

  sendBtn.addEventListener('click', async () => {
    const text = (inputEl.value || '').trim();
    if (!text) return;
    mhIdeSetBusy(true);
    mhIdeSetError('');
    mhIdeAppendMessage(logEl, 'user', text);
    inputEl.value = '';
    try {
      const reply = await mhIdeSendChat(text);
      mhIdeAppendMessage(logEl, 'assistant', reply || '(no content)');
      if (mhAutoSendOnVoice && reply) mhIdeSpeak(reply);
    } catch (e) {
      mhIdeAppendMessage(logEl, 'assistant', '(error)');
      mhIdeSetError(`Chat failed: ${e && e.message ? e.message : 'unknown_error'}`);
    } finally {
      mhIdeSetBusy(false);
    }
  });

  async function handleLiveVoice(target, btn) {
    if (mhWavStopResolve) {
      mhIdeSetStatus('Stopping…');
      mhIdeStopWavRecorder();
      return;
    }
    mhIdeSetError('');
    try {
      const { stopped } = await mhIdeStartWavRecorder(target, null, false);
      mhActiveVoiceBtn = btn;
      btn.classList.add('is-live');
      btn.textContent = 'Stop';
      mhIdeSetStatus('Listening…');
      await stopped;
      mhIdeSetStatus('Transcribing…');
      const blob = mhIdeBuildWavBlob();
      const tx = await mhIdeUploadAndTranscribeBlob(blob);
      let captured = null;
      if (mhAttachFrameOnVoice && mhCameraStream && mhMeetingLastMotionScore > 8) {
        try { captured = await mhIdeCaptureCameraFrame(target); } catch {}
      }
      if (captured && captured.url) {
        mhIdeAppendMessage(logEl, 'system', target === 'job' ? 'Camera frame attached to job.' : 'Camera frame attached to message.');
      }
      if (tx) {
        if (mhIdeIsGarbageText(tx)) {
          mhIdeAppendMessage(logEl, 'system', 'Ignored low-quality transcript (noise). Try again closer to the mic.');
          mhIdeSetStatus('');
          return;
        }
        if (target === 'job') {
          jobGoalEl.value = (jobGoalEl.value ? (jobGoalEl.value + '\n\n') : '') + tx;
          mhIdeAppendMessage(logEl, 'system', 'Live voice transcribed into job goal.');
        } else {
          if (mhAutoSendOnVoice) {
            mhIdeAppendMessage(logEl, 'user', tx);
            mhIdeSetStatus('Sending to Persona…');
            const reply = await mhIdeSendChat(tx);
            mhIdeAppendMessage(logEl, 'assistant', reply || '(no content)');
            if (reply) mhIdeSpeak(reply);
          } else {
            inputEl.value = (inputEl.value ? (inputEl.value + '\n\n') : '') + tx;
            mhIdeAppendMessage(logEl, 'system', 'Live voice transcribed into chat input.');
          }
        }
      } else {
        mhIdeAppendMessage(logEl, 'system', 'No speech was detected.');
      }
    } catch (e) {
      mhIdeSetError(`Live voice failed: ${e && e.message ? e.message : 'unknown_error'}`);
      mhIdeStopWavRecorder();
    } finally {
      mhIdeSetStatus('');
      mhActiveVoiceBtn = null;
      btn.disabled = false;
      btn.classList.remove('is-live');
      btn.textContent = 'Voice';
    }
  }

  voiceBtn.addEventListener('click', (ev) => {
    mhUploadTarget = 'chat';
    if (ev && ev.shiftKey) voiceFile.click();
    else handleLiveVoice('chat', voiceBtn);
  });
  jobVoiceBtn.addEventListener('click', (ev) => {
    mhUploadTarget = 'job';
    if (ev && ev.shiftKey) voiceFile.click();
    else handleLiveVoice('job', jobVoiceBtn);
  });

  visionBtn.addEventListener('click', (ev) => {
    mhUploadTarget = 'chat';
    if (ev && ev.shiftKey) visionFile.click();
    else {
      mhIdeSetStatus('Opening camera…');
      mhIdeOpenCameraOverlay('chat').then(() => mhIdeSetStatus('Camera ready. Capture when needed.')).catch(() => {
        mhIdeSetStatus('');
        mhIdeSetError('Live camera failed. Hold Shift to upload an image instead.');
      });
    }
  });
  jobVisionBtn.addEventListener('click', (ev) => {
    mhUploadTarget = 'job';
    if (ev && ev.shiftKey) visionFile.click();
    else {
      mhIdeSetStatus('Opening camera…');
      mhIdeOpenCameraOverlay('job').then(() => mhIdeSetStatus('Camera ready. Capture when needed.')).catch(() => {
        mhIdeSetStatus('');
        mhIdeSetError('Live camera failed. Hold Shift to upload an image instead.');
      });
    }
  });

  cameraCloseBtn.addEventListener('click', () => {
    mhIdeCloseCameraOverlay();
    if (autoSendEl && autoSendEl.checked) {
      autoSendEl.checked = false;
      mhAutoSendOnVoice = false;
      if (mhMeetingActive) {
        mhMeetingActive = false;
        mhIdeStopWavRecorder();
        mhIdeStopMeetingStreams();
        mhIdeAppendMessage(logEl, 'system', 'Meeting mode stopped.');
      }
    }
  });
  cameraCaptureBtn.addEventListener('click', async () => {
    mhIdeSetError('');
    try {
      mhIdeSetStatus('Capturing frame…');
      const overlay = document.getElementById('mhCaptureOverlay');
      const t = overlay ? (overlay.dataset.target || 'chat') : 'chat';
      const r = await mhIdeCaptureCameraFrame(t);
      mhIdeAppendMessage(logEl, 'system', r.target === 'job' ? 'Live camera frame attached to next job request.' : 'Live camera frame attached to next message.');
      mhIdeCloseCameraOverlay();
    } catch (e) {
      mhIdeSetError('Capture failed.');
    } finally {
      mhIdeSetStatus('');
    }
  });

  voiceFile.addEventListener('change', async () => {
    const file = voiceFile.files && voiceFile.files[0];
    if (!file) return;
    mhIdeSetBusy(true);
    mhIdeSetError('');
    try {
      const r = await mhIdeUpload('audio', file);
      const id = r && r.json && r.json.success ? String(r.json.id || '') : '';
      if (!id) throw new Error('upload_failed');
      const tx = await mhIdeTranscribeInboxAudio(id);
      if (tx) {
        if (mhUploadTarget === 'job') {
          jobGoalEl.value = (jobGoalEl.value ? (jobGoalEl.value + '\n\n') : '') + tx;
          mhIdeAppendMessage(logEl, 'system', 'Voice transcribed into job goal.');
        } else {
          inputEl.value = (inputEl.value ? (inputEl.value + '\n\n') : '') + tx;
          mhIdeAppendMessage(logEl, 'system', 'Voice transcribed into chat input.');
        }
      } else {
        mhIdeAppendMessage(logEl, 'system', 'Voice uploaded, but no speech was detected.');
      }
    } catch (e) {
      mhIdeSetError('Voice failed.');
    } finally {
      voiceFile.value = '';
      mhIdeSetBusy(false);
    }
  });

  visionFile.addEventListener('change', async () => {
    const file = visionFile.files && visionFile.files[0];
    if (!file) return;
    mhIdeSetBusy(true);
    mhIdeSetError('');
    try {
      const r = await mhIdeUpload('image', file);
      const url = r && r.json && r.json.success && r.json.stored && r.json.stored.url ? String(r.json.stored.url) : '';
      if (!url) throw new Error('upload_failed');
      if (mhUploadTarget === 'job') {
        mhJobUploads.images.push(url);
        mhIdeAppendMessage(logEl, 'system', 'Image attached to next job request.');
      } else {
        mhIdeUploads.images.push(url);
        mhIdeAppendMessage(logEl, 'system', 'Image attached to next message.');
      }
    } catch (e) {
      mhIdeSetError('Vision upload failed.');
    } finally {
      visionFile.value = '';
      mhIdeSetBusy(false);
    }
  });

  jobCreateBtn.addEventListener('click', async () => {
    const goal = (jobGoalEl.value || '').trim();
    if (!goal) return;
    mhIdeSetBusy(true);
    mhIdeSetError('');
    jobOutEl.textContent = '';
    jobMetaEl.textContent = '';
    mhIdeStopJobStream();
    try {
      const repoUrl = (jobRepoUrlEl.value || '').trim();
      const repoCommit = (jobRepoCommitEl.value || '').trim();
      const attachedCount = mhJobUploads.images.length;
      if (repoUrl) {
        localStorage.setItem('mh_ide_job_repo_url', repoUrl);
        if (repoCommit) localStorage.setItem('mh_ide_job_repo_commit', repoCommit);
      }

      const job = await mhIdeCreateJob(goal, repoUrl, repoCommit);
      const jobId = String(job.job_id || '');
      const attached = attachedCount ? `, images=${attachedCount}` : '';
      jobMetaEl.textContent = jobId ? `job_id: ${jobId}${attached}` : `job created${attached}`;
      jobOutEl.textContent = JSON.stringify(job, null, 2) + '\n\n';
      mhJobUploads.images = [];
      if (jobId) {
        jobStopBtn.disabled = false;
        mhJobAbort = await mhIdeStreamSse(`/hub/workbench/api/agent/jobs/events.php?job_id=${encodeURIComponent(jobId)}&since=0`, (ev) => {
          const header = ev.type ? `[${ev.type}]` : '[event]';
          if (ev.data) {
            jobOutEl.textContent += `${header} ${JSON.stringify(ev.data)}\n`;
          } else if (ev.raw) {
            jobOutEl.textContent += `${header} ${ev.raw}\n`;
          } else {
            jobOutEl.textContent += `${header}\n`;
          }
          jobOutEl.scrollTop = jobOutEl.scrollHeight;
        });
      }
    } catch (e) {
      mhIdeSetError(`Job failed: ${e && e.message ? e.message : 'unknown_error'}`);
    } finally {
      mhIdeSetBusy(false);
    }
  });

  jobStopBtn.addEventListener('click', async () => {
    mhIdeStopJobStream();
    jobMetaEl.textContent = jobMetaEl.textContent ? `${jobMetaEl.textContent} (stream stopped)` : 'stream stopped';
  });
}

document.addEventListener('DOMContentLoaded', mhIdeMain);
