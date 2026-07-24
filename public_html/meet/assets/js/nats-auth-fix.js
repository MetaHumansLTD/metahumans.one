(() => {
  if (window.__natsAuthFixInstalled) return;
  window.__natsAuthFixInstalled = true;

  const originalSend = WebSocket.prototype.send;
  const encoder = new TextEncoder();
  const decoder = new TextDecoder();
  const socketMeta = new WeakMap();

  const CONNECT_PREFIX_BYTES = [67, 79, 78, 78, 69, 67, 84, 32];
  const SUB_PREFIX_BYTES = [83, 85, 66, 32];
  const PUB_PREFIX_BYTES = [80, 85, 66, 32];
  const HPUB_PREFIX_BYTES = [72, 80, 85, 66, 32];
  const ASCII_MAX = 126;

  function looksLikeJwt(v) {
    return typeof v === "string" && v.startsWith("eyJ") && v.includes(".");
  }

  function b64UrlDecodeToString(s) {
    const padded = s.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((s.length + 3) % 4);
    try {
      return atob(padded);
    } catch {
      return null;
    }
  }

  function parseJwtPayload(token) {
    if (!looksLikeJwt(token)) return null;
    const parts = token.split(".");
    if (parts.length < 2) return null;
    const payloadJson = b64UrlDecodeToString(parts[1]);
    if (!payloadJson) return null;
    try {
      return JSON.parse(payloadJson);
    } catch {
      return null;
    }
  }

  function upsertSocketMeta(ws, tokenMaybe) {
    const payload = parseJwtPayload(tokenMaybe);
    if (!payload) return;
    const userId = payload.user_id || payload.userId || payload.sub;
    const roomId = payload.room_id || payload.roomId;
    if (!userId || !roomId) return;
    const existing = socketMeta.get(ws);
    if (existing && existing.userId === userId && existing.roomId === roomId) return;
    socketMeta.set(ws, { userId, roomId, initSent: false });
  }

  function encodeProtoStringField(fieldNumber, value) {
    const valueBytes = encoder.encode(value);
    const tag = (fieldNumber << 3) | 2; // length-delimited
    if (valueBytes.length >= 128 || tag >= 128) return null;
    const out = new Uint8Array(2 + valueBytes.length);
    out[0] = tag;
    out[1] = valueBytes.length;
    out.set(valueBytes, 2);
    return out;
  }

  function sendReqInitialData(ws) {
    const meta = socketMeta.get(ws);
    if (!meta || meta.initSent) return;
    if (typeof ws.url === "string" && !ws.url.includes("/ws")) return;

    const payload = encodeProtoStringField(1, "init_" + Date.now().toString(36));
    if (!payload) return;

    const subject = `sysJsWorker.${meta.roomId}.${meta.userId}`;
    const prefix = encoder.encode(`PUB ${subject} ${payload.length}\r\n`);
    const suffix = encoder.encode("\r\n");
    const buf = new Uint8Array(prefix.length + payload.length + suffix.length);
    buf.set(prefix, 0);
    buf.set(payload, prefix.length);
    buf.set(suffix, prefix.length + payload.length);
    originalSend.call(ws, buf);
    meta.initSent = true;
  }

  function rewriteConnectLine(ws, line) {
    const crlfIndex = line.indexOf("\r\n");
    if (!line.startsWith("CONNECT ") || crlfIndex === -1) return null;

    const jsonPart = line.slice(8, crlfIndex);
    try {
      const payload = JSON.parse(jsonPart);
      if (
        typeof payload === "object" &&
        payload &&
        !payload.auth_token &&
        (looksLikeJwt(payload.user) || looksLikeJwt(payload.pass) || looksLikeJwt(payload.token))
      ) {
        const token = looksLikeJwt(payload.user)
          ? payload.user
          : looksLikeJwt(payload.pass)
            ? payload.pass
            : payload.token;
        payload.auth_token = token;
        delete payload.user;
        delete payload.pass;
        delete payload.token;
        upsertSocketMeta(ws, token);
        setTimeout(() => sendReqInitialData(ws), 200);
        return "CONNECT " + JSON.stringify(payload) + "\r\n";
      }
      if (payload && looksLikeJwt(payload.auth_token)) {
        upsertSocketMeta(ws, payload.auth_token);
        setTimeout(() => sendReqInitialData(ws), 200);
      }
    } catch {}

    return null;
  }

  function rewriteTextCommands(ws, data) {
    const endsWithCrlf = data.endsWith("\r\n");
    const lines = data.split("\r\n");
    const out = [];
    let changed = false;

    for (const line of lines) {
      if (!line) continue;
      const connectRewritten = rewriteConnectLine(ws, line + "\r\n");
      if (connectRewritten) {
        out.push(connectRewritten.slice(0, -2));
        changed = true;
        continue;
      }

      out.push(line);
    }

    if (!changed) return null;
    return out.join("\r\n") + (endsWithCrlf ? "\r\n" : "");
  }

  function rewriteString(ws, data) {
    return rewriteTextCommands(ws, data);
  }

  function isLikelyAscii(bytes) {
    for (let i = 0; i < bytes.length; i++) {
      const b = bytes[i];
      if (b === 10 || b === 13) continue;
      if (b < 32 || b > ASCII_MAX) return false;
    }
    return true;
  }

  function rewriteBytes(ws, bytes) {
    if (bytes.length < 10) return null;

    function findCrlfIndex() {
      for (let i = 0; i < bytes.length - 1; i++) {
        if (bytes[i] === 13 && bytes[i + 1] === 10) return i;
      }
      return -1;
    }

    if (
      bytes[0] === CONNECT_PREFIX_BYTES[0] &&
      bytes[1] === CONNECT_PREFIX_BYTES[1] &&
      bytes[2] === CONNECT_PREFIX_BYTES[2] &&
      bytes[3] === CONNECT_PREFIX_BYTES[3] &&
      bytes[4] === CONNECT_PREFIX_BYTES[4] &&
      bytes[5] === CONNECT_PREFIX_BYTES[5] &&
      bytes[6] === CONNECT_PREFIX_BYTES[6] &&
      bytes[7] === CONNECT_PREFIX_BYTES[7]
    ) {
      if (!isLikelyAscii(bytes)) return null;
      const asText = decoder.decode(bytes);
      const rewritten = rewriteTextCommands(ws, asText);
      return rewritten ? encoder.encode(rewritten) : null;
    }

    if (
      bytes[0] === SUB_PREFIX_BYTES[0] &&
      bytes[1] === SUB_PREFIX_BYTES[1] &&
      bytes[2] === SUB_PREFIX_BYTES[2] &&
      bytes[3] === SUB_PREFIX_BYTES[3]
    ) {
      if (!isLikelyAscii(bytes)) return null;
      const asText = decoder.decode(bytes);
      const rewritten = rewriteTextCommands(ws, asText);
      return rewritten ? encoder.encode(rewritten) : null;
    }

    if (
      bytes[0] === PUB_PREFIX_BYTES[0] &&
      bytes[1] === PUB_PREFIX_BYTES[1] &&
      bytes[2] === PUB_PREFIX_BYTES[2] &&
      bytes[3] === PUB_PREFIX_BYTES[3]
    ) {
      const crlfIndex = findCrlfIndex();
      if (crlfIndex === -1) return null;
      const lineBytes = bytes.slice(0, crlfIndex + 2);
      if (!isLikelyAscii(lineBytes)) return null;
      const lineText = decoder.decode(lineBytes);
      const rewrittenLineText = rewriteTextCommands(ws, lineText);
      if (!rewrittenLineText) return null;
      const rewrittenLineBytes = encoder.encode(rewrittenLineText);
      const out = new Uint8Array(rewrittenLineBytes.length + (bytes.length - (crlfIndex + 2)));
      out.set(rewrittenLineBytes, 0);
      out.set(bytes.slice(crlfIndex + 2), rewrittenLineBytes.length);
      return out;
    }

    if (
      bytes[0] === HPUB_PREFIX_BYTES[0] &&
      bytes[1] === HPUB_PREFIX_BYTES[1] &&
      bytes[2] === HPUB_PREFIX_BYTES[2] &&
      bytes[3] === HPUB_PREFIX_BYTES[3] &&
      bytes[4] === HPUB_PREFIX_BYTES[4]
    ) {
      const crlfIndex = findCrlfIndex();
      if (crlfIndex === -1) return null;
      const lineBytes = bytes.slice(0, crlfIndex + 2);
      if (!isLikelyAscii(lineBytes)) return null;
      const lineText = decoder.decode(lineBytes);
      const rewrittenLineText = rewriteTextCommands(ws, lineText);
      if (!rewrittenLineText) return null;
      const rewrittenLineBytes = encoder.encode(rewrittenLineText);
      const out = new Uint8Array(rewrittenLineBytes.length + (bytes.length - (crlfIndex + 2)));
      out.set(rewrittenLineBytes, 0);
      out.set(bytes.slice(crlfIndex + 2), rewrittenLineBytes.length);
      return out;
    }
    return null;
  }

  WebSocket.prototype.send = function (data) {
    try {
      if (typeof data === "string") {
        const rewritten = rewriteString(this, data);
        if (rewritten) return originalSend.call(this, rewritten);
        return originalSend.call(this, data);
      }

      let bytes = null;
      if (data instanceof ArrayBuffer) bytes = new Uint8Array(data);
      else if (ArrayBuffer.isView(data))
        bytes = new Uint8Array(data.buffer, data.byteOffset, data.byteLength);

      if (bytes) {
        const rewrittenBytes = rewriteBytes(this, bytes);
        if (rewrittenBytes) return originalSend.call(this, rewrittenBytes);
      }
    } catch {}

    return originalSend.call(this, data);
  };
})();

(() => {
  if (window.__mh_persona_bot_ui_v1) return;
  window.__mh_persona_bot_ui_v1 = Date.now();

  const SHARE_OVERLAY_ID = "mh-share-overlay";
  let mhShareState = null;

  function mhFindBestShareVideo() {
    try {
      const vids = Array.from(document.querySelectorAll("video"));
      let best = null;
      let bestScore = 0;
      for (const v of vids) {
        const r = v.getBoundingClientRect();
        const w = r.width || 0;
        const h = r.height || 0;
        if (w < 220 || h < 160) continue;
        let score = w * h;
        const ar = w / Math.max(1, h);
        if (ar > 1.25) score *= 1.15;
        try {
          const so = v.srcObject;
          const tracks = so && so.getVideoTracks ? so.getVideoTracks() : [];
          const lbl = tracks && tracks[0] && tracks[0].label ? String(tracks[0].label).toLowerCase() : "";
          if (lbl.includes("screen") || lbl.includes("window") || lbl.includes("tab")) score *= 1.4;
        } catch {}
        if (score > bestScore) {
          bestScore = score;
          best = v;
        }
      }
      return best;
    } catch (e) {
      return null;
    }
  }

  function mhToggleShareMax() {
    try {
      const existing = document.getElementById(SHARE_OVERLAY_ID);
      if (existing && mhShareState && mhShareState.video) {
        try {
          const v = mhShareState.video;
          v.style.position = mhShareState.prevStyle.position || "";
          v.style.inset = mhShareState.prevStyle.inset || "";
          v.style.width = mhShareState.prevStyle.width || "";
          v.style.height = mhShareState.prevStyle.height || "";
          v.style.objectFit = mhShareState.prevStyle.objectFit || "";
          v.style.zIndex = mhShareState.prevStyle.zIndex || "";
          v.style.borderRadius = mhShareState.prevStyle.borderRadius || "";
          v.style.background = mhShareState.prevStyle.background || "";
          if (mhShareState.next && mhShareState.next.parentNode === mhShareState.parent) {
            mhShareState.parent.insertBefore(v, mhShareState.next);
          } else {
            mhShareState.parent.appendChild(v);
          }
        } catch {}
        try {
          existing.parentNode && existing.parentNode.removeChild(existing);
        } catch {}
        mhShareState = null;
        return;
      }

      const v = mhFindBestShareVideo();
      if (!v || !v.parentNode) return;
      const parent = v.parentNode;
      const next = v.nextSibling;
      const prevStyle = {
        position: v.style.position,
        inset: v.style.inset,
        width: v.style.width,
        height: v.style.height,
        objectFit: v.style.objectFit,
        zIndex: v.style.zIndex,
        borderRadius: v.style.borderRadius,
        background: v.style.background,
      };
      const overlay = document.createElement("div");
      overlay.id = SHARE_OVERLAY_ID;
      overlay.style.cssText =
        "position:fixed;inset:0;z-index:2147483647;background:rgba(0,0,0,.92);display:flex;align-items:center;justify-content:center;padding:14px;";
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) mhToggleShareMax();
      });
      const close = document.createElement("button");
      close.type = "button";
      close.textContent = "Back";
      close.style.cssText =
        "position:fixed;top:14px;right:14px;z-index:2147483647;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.08);color:#e8eefc;padding:10px 12px;font-weight:950;cursor:pointer;";
      close.onclick = () => mhToggleShareMax();
      overlay.appendChild(close);

      mhShareState = { video: v, parent, next, prevStyle };
      document.body.appendChild(overlay);

      v.style.position = "fixed";
      v.style.inset = "0";
      v.style.width = "100vw";
      v.style.height = "100vh";
      v.style.objectFit = "contain";
      v.style.zIndex = "2147483646";
      v.style.borderRadius = "0";
      v.style.background = "black";
      overlay.appendChild(v);
    } catch (e) {}
  }

  async function mhToggleShareFullscreen() {
    try {
      if (document.fullscreenElement) {
        await document.exitFullscreen();
        return;
      }
      const v = mhFindBestShareVideo();
      if (!v || !v.requestFullscreen) return;
      await v.requestFullscreen();
    } catch (e) {}
  }

  function mhShareCleanup() {
    try {
      if (document.getElementById(SHARE_OVERLAY_ID)) {
        mhToggleShareMax();
      }
    } catch (e) {}
  }

  try {
    window.addEventListener("beforeunload", mhShareCleanup, { capture: true });
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) mhShareCleanup();
    });
    let lastHref = location.href;
    setInterval(() => {
      if (location.href !== lastHref) {
        lastHref = location.href;
        mhShareCleanup();
      }
    }, 1000);
  } catch (e) {}

  try {
    window.__mh_livekit_inroom_hook_v1 = 1;
    window.__mh_livekit_inroom_loaded = 1;
    window.__mh_livekit_inroom_v2_loaded = 1;
    window.__mh_inroom_ui_v2 = 1;
  } catch (e) {}

  const ROOT_ID = "mh-meet-autobot";
  const OLD_IDS = [
    "mh-livekit-inroom-toggle",
    "mh-livekit-inroom-panel",
    "mh-livekit-inroom-style",
    "mh-livekit-inroom-toggle-v2",
    "mh-livekit-inroom-panel-v2",
  ];

  function cleanupOld() {
    try {
      for (const id of OLD_IDS) {
        const el = document.getElementById(id);
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
      const dup = document.querySelectorAll(`#${ROOT_ID}`);
      for (let i = 1; i < dup.length; i++) {
        const el = dup[i];
        if (el && el.parentNode) el.parentNode.removeChild(el);
      }
    } catch (e) {}
  }

  function getAccessToken() {
    try {
      const qs = new URLSearchParams(window.location.search || "");
      return (qs.get("access_token") || "").trim();
    } catch (e) {
      return "";
    }
  }

  async function fetchPersonas() {
    try {
      const tok = getAccessToken();
      const r = await fetch("/v1/meet/personas.php", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ access_token: tok }),
      });
      const j = await r.json().catch(() => null);
      if (!j || j.ok !== true || !Array.isArray(j.personas)) return [];
      return j.personas
        .map((p) => ({
          persona_id: p && typeof p.persona_id === "string" ? p.persona_id : "",
          label: p && typeof p.label === "string" ? p.label : "",
        }))
        .filter((p) => p.persona_id);
    } catch (e) {
      return [];
    }
  }

  async function startBot(personaId) {
    const tok = getAccessToken();
    const payload = {
      access_token: tok,
      persona_id: personaId || "",
      bot_name: personaId ? String(personaId) : "Meta Human Persona",
      role: "presenter",
    };
    const r = await fetch("/v1/meet/bot_start.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const j = await r.json().catch(() => null);
    return { ok: !!(j && j.ok === true), status: r.status, json: j };
  }

  function ensureUI() {
    cleanupOld();
    const d = document;
    const parent = d.body || d.documentElement;
    if (!parent) return null;

    let root = d.getElementById(ROOT_ID);
    if (root) return root;

    root = d.createElement("div");
    root.id = ROOT_ID;
    root.style.cssText =
      "position:fixed;right:18px;bottom:18px;z-index:2147483647;pointer-events:auto;display:flex;flex-direction:column;align-items:flex-end;gap:10px;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#e8eefc;";

    const btn = d.createElement("button");
    btn.type = "button";
    btn.textContent = "Persona Bot";
    btn.style.cssText =
      "border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(12,18,34,.92);color:#e8eefc;padding:10px 12px;font-weight:950;cursor:pointer;box-shadow:0 10px 30px rgba(0,0,0,.35);";

    const panel = d.createElement("div");
    panel.style.cssText =
      "width:360px;max-width:calc(100vw - 36px);border-radius:16px;border:1px solid rgba(255,255,255,.14);background:rgba(12,18,34,.92);backdrop-filter:blur(10px);overflow:hidden;display:none;flex-direction:column;gap:10px;padding:12px;";

    const title = d.createElement("div");
    title.textContent = "MetaHumans Bot";
    title.style.cssText = "font-weight:950;font-size:12px;letter-spacing:.03em;text-transform:uppercase;opacity:.9;";

    const row = d.createElement("div");
    row.style.cssText = "display:flex;flex-direction:column;gap:6px;";
    const label = d.createElement("div");
    label.textContent = "Choose persona";
    label.style.cssText = "font-weight:900;font-size:12px;opacity:.85;";
    const sel = d.createElement("select");
    sel.style.cssText =
      "width:100%;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e8eefc;padding:10px 10px;font-weight:800;";
    row.appendChild(label);
    row.appendChild(sel);

    const actions = d.createElement("div");
    actions.style.cssText = "display:flex;gap:10px;justify-content:flex-end;";
    const close = d.createElement("button");
    close.type = "button";
    close.textContent = "Close";
    close.style.cssText =
      "border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e8eefc;padding:10px 12px;font-weight:950;cursor:pointer;";
    const start = d.createElement("button");
    start.type = "button";
    start.textContent = "Activate";
    start.style.cssText =
      "border-radius:12px;border:1px solid rgba(0,212,255,.45);background:rgba(0,212,255,.12);color:#e8eefc;padding:10px 12px;font-weight:950;cursor:pointer;";
    actions.appendChild(close);
    actions.appendChild(start);

    const tools = d.createElement("div");
    tools.style.cssText = "display:flex;gap:10px;justify-content:flex-start;flex-wrap:wrap;";
    const shareMax = d.createElement("button");
    shareMax.type = "button";
    shareMax.textContent = "Share Max";
    shareMax.style.cssText =
      "border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e8eefc;padding:10px 12px;font-weight:950;cursor:pointer;";
    shareMax.onclick = () => mhToggleShareMax();
    const shareFs = d.createElement("button");
    shareFs.type = "button";
    shareFs.textContent = "Share FS";
    shareFs.style.cssText =
      "border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.06);color:#e8eefc;padding:10px 12px;font-weight:950;cursor:pointer;";
    shareFs.onclick = () => mhToggleShareFullscreen();
    tools.appendChild(shareMax);
    tools.appendChild(shareFs);

    const status = d.createElement("div");
    status.style.cssText = "font-size:12px;opacity:.85;min-height:16px;";
    status.textContent = "";

    panel.appendChild(title);
    panel.appendChild(row);
    panel.appendChild(tools);
    panel.appendChild(actions);
    panel.appendChild(status);

    btn.onclick = () => {
      panel.style.display = panel.style.display === "none" || panel.style.display === "" ? "flex" : "none";
    };
    close.onclick = () => {
      panel.style.display = "none";
    };

    start.onclick = async () => {
      try {
        const personaId = String(sel.value || "").trim();
        if (!personaId) {
          status.textContent = "Choose a persona.";
          return;
        }
        status.textContent = "Starting…";
        const r = await startBot(personaId);
        if (!r.ok) {
          const err = r && r.json && (r.json.error || r.json.detail) ? String(r.json.error || r.json.detail) : "";
          status.textContent = err ? "Start failed: " + err : "Start failed (" + String(r.status || "") + ")";
          return;
        }
        status.textContent = "Active.";
      } catch (e) {
        status.textContent = "Start failed.";
      }
    };

    root.appendChild(btn);
    root.appendChild(panel);
    parent.appendChild(root);

    root.__mh_sel = sel;
    root.__mh_status = status;
    return root;
  }

  window.addEventListener("message", function (ev) {
    try {
      const d = ev && ev.data ? ev.data : null;
      if (!d || typeof d !== "object") return;
      if (d.type === "mh_share_max") mhToggleShareMax();
      if (d.type === "mh_share_fullscreen") mhToggleShareFullscreen();
    } catch (e) {}
  });

  async function hydrate() {
    const ui = ensureUI();
    if (!ui) return;
    const sel = ui.__mh_sel;
    const items = await fetchPersonas();
    while (sel.firstChild) sel.removeChild(sel.firstChild);
    if (items.length === 0) {
      const o = document.createElement("option");
      o.value = "";
      o.textContent = "No personas found";
      sel.appendChild(o);
      return;
    }
    for (const it of items) {
      const o = document.createElement("option");
      o.value = it.persona_id;
      o.textContent = it.label || it.persona_id;
      sel.appendChild(o);
    }
  }

  try {
    if (document.readyState === "loading") {
      document.addEventListener(
        "DOMContentLoaded",
        function () {
          hydrate();
        },
        { once: true }
      );
    } else {
      hydrate();
    }
  } catch (e) {}
})();
