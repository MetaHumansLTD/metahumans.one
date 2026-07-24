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
