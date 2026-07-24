(() => {
  if (window.__mhAiVoiceInstalled) return;
  window.__mhAiVoiceInstalled = true;

  const TOKEN = "demo-insights-token";
  const FIXED_ID = "mh-ai-voice-fixed-btn";

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  function toBase64(blob) {
    return new Promise((resolve, reject) => {
      const fr = new FileReader();
      fr.onerror = () => reject(new Error("file_read_failed"));
      fr.onload = () => {
        const s = String(fr.result || "");
        const idx = s.indexOf("base64,");
        resolve(idx >= 0 ? s.slice(idx + 7) : "");
      };
      fr.readAsDataURL(blob);
    });
  }

  async function transcribe(blob) {
    const b64 = await toBase64(blob);
    if (!b64) throw new Error("empty_audio");
    const r = await fetch("/cue-insights/transcribe", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CUE-GPU-TOKEN": TOKEN },
      body: JSON.stringify({ audio_base64: b64, lang: "auto" }),
      credentials: "same-origin",
    });
    const j = await r.json().catch(() => null);
    if (!j || typeof j.text !== "string") throw new Error("transcribe_failed");
    return j.text.trim();
  }

  async function personaSettings() {
    try {
      const r = await fetch("/hub/workbench/api/personas.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
        body: "{}",
      });
      const j = await r.json().catch(() => null);
      const s = (j && j.selected_settings && typeof j.selected_settings === "object") ? j.selected_settings : {};
      return {
        speech_engine: String(s.speech_engine || "personaplex").toLowerCase(),
        personaplex_voice: String(s.personaplex_voice || "NATF2").toUpperCase(),
        persona_prompt: String(s.persona_prompt || "You are a helpful English assistant."),
      };
    } catch (e) {
      return { speech_engine: "personaplex", personaplex_voice: "NATF2", persona_prompt: "You are a helpful English assistant." };
    }
  }

  async function aiChat(text) {
    const history = [{ role: "user", text: String(text || "").trim() + "\n\nRespond in English only." }];
    const r = await fetch("/cue-insights/chat", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CUE-GPU-TOKEN": TOKEN },
      credentials: "same-origin",
      body: JSON.stringify({ history }),
    });
    const j = await r.json().catch(() => null);
    if (!j || typeof j.text !== "string") throw new Error("chat_failed");
    return j.text.trim();
  }

  async function speakText(text, settings) {
    const r = await fetch("/cue-insights/speak", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CUE-GPU-TOKEN": TOKEN },
      credentials: "same-origin",
      body: JSON.stringify({
        text,
        speech_engine: settings.speech_engine,
        personaplex_voice: settings.personaplex_voice,
        persona_prompt: settings.persona_prompt,
      }),
    });
    const j = await r.json().catch(() => null);
    if (!j || typeof j.audio_base64 !== "string" || !j.audio_base64) throw new Error("speak_failed");
    const audio = new Audio("data:" + (j.audio_mime || "audio/wav") + ";base64," + j.audio_base64);
    audio.volume = 1.0;
    try { await audio.play(); } catch (e) {}
  }

  function encodeWavPcm16(samples, sampleRate) {
    const b = new ArrayBuffer(44 + samples.length * 2);
    const v = new DataView(b);
    function wstr(off, s) {
      for (let i = 0; i < s.length; i++) v.setUint8(off + i, s.charCodeAt(i));
    }
    wstr(0, "RIFF");
    v.setUint32(4, 36 + samples.length * 2, true);
    wstr(8, "WAVE");
    wstr(12, "fmt ");
    v.setUint32(16, 16, true);
    v.setUint16(20, 1, true);
    v.setUint16(22, 1, true);
    v.setUint32(24, sampleRate, true);
    v.setUint32(28, sampleRate * 2, true);
    v.setUint16(32, 2, true);
    v.setUint16(34, 16, true);
    wstr(36, "data");
    v.setUint32(40, samples.length * 2, true);
    let o = 44;
    for (let i = 0; i < samples.length; i++) {
      let s = samples[i];
      if (s > 1) s = 1;
      if (s < -1) s = -1;
      v.setInt16(o, s < 0 ? s * 0x8000 : s * 0x7fff, true);
      o += 2;
    }
    return new Blob([b], { type: "audio/wav" });
  }

  function resampleTo(input, inRate, outRate) {
    if (inRate === outRate) return input;
    const ratio = inRate / outRate;
    const outLen = Math.max(1, Math.floor(input.length / ratio));
    const out = new Float32Array(outLen);
    for (let i = 0; i < outLen; i++) {
      const pos = i * ratio;
      const p0 = Math.floor(pos);
      const p1 = Math.min(input.length - 1, p0 + 1);
      const t = pos - p0;
      out[i] = input[p0] * (1 - t) + input[p1] * t;
    }
    return out;
  }

  async function recordOnce(ms) {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const src = ctx.createMediaStreamSource(stream);
    const node = ctx.createScriptProcessor(4096, 1, 1);
    const chunks = [];
    node.onaudioprocess = (e) => {
      const ch = e.inputBuffer.getChannelData(0);
      chunks.push(new Float32Array(ch));
    };
    src.connect(node);
    node.connect(ctx.destination);

    await sleep(ms);

    node.disconnect();
    src.disconnect();
    stream.getTracks().forEach((t) => t.stop());
    try {
      await ctx.close();
    } catch (e) {}

    let total = 0;
    for (const c of chunks) total += c.length;
    const all = new Float32Array(total);
    let off = 0;
    for (const c of chunks) {
      all.set(c, off);
      off += c.length;
    }

    const targetRate = 16000;
    const resampled = resampleTo(all, ctx.sampleRate || 48000, targetRate);
    return encodeWavPcm16(resampled, targetRate);
  }

  function findAiModal() {
    const all = Array.from(document.querySelectorAll("div"));
    for (const el of all) {
      const t = (el.textContent || "").trim();
      if (!t) continue;
      if (t.includes("AI Assistant Chat") || t.includes("AI assistant chat")) {
        let p = el;
        for (let i = 0; i < 6; i++) {
          if (!p) break;
          if (p.querySelector && p.querySelector("textarea, input")) return p;
          p = p.parentElement;
        }
        return el.closest("div") || el;
      }
    }
    return null;
  }

  function ensureFixedButton() {
    if (document.getElementById(FIXED_ID)) return;
    const btn = document.createElement("button");
    btn.type = "button";
    btn.id = FIXED_ID;
    btn.textContent = "AI Mic";
    btn.style.cssText =
      "position:fixed;right:18px;bottom:78px;z-index:2147483647;height:40px;padding:0 12px;border-radius:14px;border:1px solid rgba(255,255,255,.14);background:rgba(12,18,34,.92);color:#e8eefc;font-weight:950;cursor:pointer;box-shadow:0 10px 30px rgba(0,0,0,.35);";
    document.body.appendChild(btn);

    let busy = false;
    btn.addEventListener("click", async () => {
      if (busy) return;
      busy = true;
      const old = btn.textContent;
      btn.textContent = "Listening…";
      try {
        const blob = await recordOnce(3500);
        btn.textContent = "Transcribing…";
        const text = await transcribe(blob);
        if (!text) throw new Error("empty_text");
        const settings = await personaSettings();

        const modal = findAiModal();
        const input = (modal && modal.querySelector && modal.querySelector("textarea, input")) || document.activeElement;
        if (input && typeof input.focus === "function") {
          input.focus();
          input.value = text;
          input.dispatchEvent(new Event("input", { bubbles: true }));
          const scope = modal || document;
          const sendBtn =
            scope.querySelector("button[type='submit']") ||
            Array.from(scope.querySelectorAll("button")).find((b) => {
              const tt = (b.textContent || "").trim().toLowerCase();
              return tt === "send" || tt === "submit";
            });
          if (sendBtn) {
            sendBtn.click();
          } else {
            input.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", code: "Enter", bubbles: true }));
          }
        }
        btn.textContent = "Thinking…";
        const reply = await aiChat(text);
        if (reply) {
          btn.textContent = "Speaking…";
          await speakText(reply, settings);
        }
      } catch (e) {
      } finally {
        btn.textContent = old;
        busy = false;
      }
    });
  }

  function ensureButton(modal) {
    if (!modal || modal.querySelector("#mh-ai-voice-btn")) return;
    const input = modal.querySelector("textarea, input");
    if (!input) return;

    const btn = document.createElement("button");
    btn.type = "button";
    btn.id = "mh-ai-voice-btn";
    btn.textContent = "Mic";
    btn.style.cssText =
      "position:absolute;right:52px;bottom:10px;z-index:99999;width:36px;height:32px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.08);color:#fff;font-weight:900;cursor:pointer;";

    const wrap = modal;
    const cs = getComputedStyle(wrap);
    if (cs.position === "static") wrap.style.position = "relative";
    wrap.appendChild(btn);

    let busy = false;
    btn.addEventListener("click", async () => {
      if (busy) return;
      busy = true;
      const old = btn.textContent;
      btn.textContent = "…";
      try {
        const blob = await recordOnce(3500);
        const text = await transcribe(blob);
        if (!text) throw new Error("empty_text");
        const settings = await personaSettings();
        input.focus();
        input.value = text;
        input.dispatchEvent(new Event("input", { bubbles: true }));
        const sendBtn =
          modal.querySelector("button[type='submit']") ||
          Array.from(modal.querySelectorAll("button")).find((b) => {
            const tt = (b.textContent || "").trim().toLowerCase();
            return tt === "send" || tt === "submit";
          });
        if (sendBtn) {
          sendBtn.click();
        } else {
          input.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", code: "Enter", bubbles: true }));
        }
        const reply = await aiChat(text);
        if (reply) await speakText(reply, settings);
      } catch (e) {
      } finally {
        btn.textContent = old;
        busy = false;
      }
    });
  }

  setInterval(() => {
    try {
      ensureFixedButton();
      const modal = findAiModal();
      if (modal) ensureButton(modal);
    } catch (e) {}
  }, 900);
})();
