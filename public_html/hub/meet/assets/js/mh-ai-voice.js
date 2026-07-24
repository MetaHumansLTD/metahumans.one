(() => {
  if (window.__mhAiVoiceInstalled) return;
  window.__mhAiVoiceInstalled = true;
  if (window.top !== window.self) return;

  const FIXED_ID = "mh-ai-voice-fixed-btn";
  const FRAME_ID = "mhMeetFrame";
  const MOUNT_ID = "mh-overlay-mount";
  const SCRIPT_ID = "mh-meet-overlay-widget-from-mic";
  const WIDGET_SRC = "/hub/genesis/widget.js";
  const BTN_IDLE_STYLE =
    "position:fixed;right:18px;bottom:76px;z-index:2147483647;min-width:118px;height:46px;padding:0 16px;border-radius:999px;border:1px solid rgba(255,215,120,.38);background:radial-gradient(circle at 35% 35%,rgba(120,220,255,.28),rgba(10,30,70,.92) 60%,rgba(12,18,34,.96) 100%);color:#e8eefc;font-weight:950;cursor:pointer;box-shadow:0 14px 40px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.10) inset;transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;";
  const BTN_ACTIVE_STYLE =
    "position:fixed;right:18px;bottom:76px;z-index:2147483647;min-width:118px;height:46px;padding:0 16px;border-radius:999px;border:1px solid rgba(0,212,255,.55);background:radial-gradient(circle at 35% 35%,rgba(120,220,255,.40),rgba(10,30,70,.95) 60%,rgba(12,18,34,.98) 100%);color:#f6fbff;font-weight:950;cursor:pointer;box-shadow:0 14px 40px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.12) inset,0 0 24px rgba(0,212,255,.22);transition:transform .15s ease,box-shadow .15s ease,border-color .15s ease;";

  const run = {
    btn: null,
    phase: "idle",
    activateTimer: null,
    widgetPromise: null,
  };

  function getMeetDocument() {
    try {
      const frame = document.getElementById(FRAME_ID);
      if (frame && frame.contentDocument) return frame.contentDocument;
    } catch (e) {}
    return document;
  }

  function cleanupEmbeddedButton() {
    try {
      const frameDoc = getMeetDocument();
      if (!frameDoc || frameDoc === document) return;
      const btn = frameDoc.getElementById(FIXED_ID);
      if (btn && btn.parentNode) btn.parentNode.removeChild(btn);
    } catch (e) {}
  }

  function clearActivateTimer() {
    try {
      if (run.activateTimer) clearTimeout(run.activateTimer);
    } catch (e) {}
    run.activateTimer = null;
  }

  function setButtonPhase(phase) {
    if (!run.btn) return;
    run.phase = phase;
    if (phase === "active") {
      run.btn.textContent = "Stop Mic";
      run.btn.title = "Stop AI Mic";
      run.btn.style.cssText = BTN_ACTIVE_STYLE;
      run.btn.setAttribute("aria-pressed", "true");
      return;
    }
    if (phase === "connecting") {
      run.btn.textContent = "Connecting";
      run.btn.title = "Connecting AI Mic";
      run.btn.style.cssText = BTN_ACTIVE_STYLE;
      run.btn.setAttribute("aria-pressed", "true");
      return;
    }
    run.btn.textContent = "AI Mic";
    run.btn.title = "AI Mic";
    run.btn.style.cssText = BTN_IDLE_STYLE;
    run.btn.setAttribute("aria-pressed", "false");
  }

  function ensureMount() {
    let mount = document.getElementById(MOUNT_ID);
    if (!mount) {
      mount = document.createElement("div");
      mount.id = MOUNT_ID;
      mount.setAttribute("data-mh-widget", "metahuman-overlay");
      mount.setAttribute("data-version", "latest");
      mount.setAttribute("data-hub-base", "/hub");
      mount.setAttribute("data-autostart", "0");
      mount.setAttribute("data-default-mode", "auto");
      (document.body || document.documentElement).appendChild(mount);
    }
    if (!mount.getAttribute("data-hub-base")) mount.setAttribute("data-hub-base", "/hub");
    if (!mount.getAttribute("data-default-mode")) mount.setAttribute("data-default-mode", "auto");
    mount.setAttribute("data-autostart", "0");
    return mount;
  }

  function getWidget() {
    return window.MetaHumansOverlayWidget && typeof window.MetaHumansOverlayWidget.open === "function"
      ? window.MetaHumansOverlayWidget
      : null;
  }

  function mountWidgetIfReady() {
    const widget = getWidget();
    if (!widget) return null;
    const mount = ensureMount();
    try {
      widget.mount({
        hubBase: mount.getAttribute("data-hub-base") || "/hub",
        config: {
          wgt_metahuman_overlay_default_mode: mount.getAttribute("data-default-mode") || "auto",
        },
      });
    } catch (e) {}
    return widget;
  }

  function ensureWidgetReady() {
    if (run.widgetPromise) return run.widgetPromise;
    run.widgetPromise = new Promise((resolve, reject) => {
      const readyWidget = mountWidgetIfReady();
      if (readyWidget) {
        resolve(readyWidget);
        return;
      }

      const existing = document.getElementById(SCRIPT_ID);
      if (existing) {
        const check = () => {
          const widget = mountWidgetIfReady();
          if (widget) resolve(widget);
          else reject(new Error("widget_not_ready"));
        };
        existing.addEventListener("load", check, { once: true });
        existing.addEventListener("error", () => reject(new Error("widget_load_failed")), { once: true });
        return;
      }

      const s = document.createElement("script");
      s.id = SCRIPT_ID;
      s.src = WIDGET_SRC + "?v=" + Date.now();
      s.async = true;
      s.onload = () => {
        const widget = mountWidgetIfReady();
        if (widget) resolve(widget);
        else reject(new Error("widget_mount_failed"));
      };
      s.onerror = () => reject(new Error("widget_load_failed"));
      (document.head || document.documentElement).appendChild(s);
    }).catch((err) => {
      run.widgetPromise = null;
      throw err;
    });
    return run.widgetPromise;
  }

  function getRealtimeFrame() {
    try {
      const mount = document.getElementById(MOUNT_ID);
      const frame = mount && mount.querySelector ? mount.querySelector("iframe") : null;
      if (frame && frame.contentWindow) return frame;
    } catch (e) {}
    return null;
  }

  function postRealtimeCommand(cmd) {
    try {
      const frame = getRealtimeFrame();
      if (!frame || !frame.contentWindow) return false;
      frame.contentWindow.postMessage({ type: "mh_realtime_command", cmd: String(cmd || "") }, window.location.origin);
      return true;
    } catch (e) {
      return false;
    }
  }

  async function activateViaWidget() {
    setButtonPhase("connecting");
    clearActivateTimer();
    const widget = await ensureWidgetReady();
    try { widget.open(); } catch (e) {}
    setTimeout(() => {
      try { postRealtimeCommand("activate"); } catch (e) {}
    }, 180);
    run.activateTimer = setTimeout(() => {
      if (run.phase === "connecting") setButtonPhase("active");
    }, 2500);
  }

  async function deactivateViaWidget() {
    clearActivateTimer();
    const widget = await ensureWidgetReady().catch(() => null);
    if (widget && typeof widget.close === "function") {
      try { widget.close(); } catch (e) {}
    } else {
      postRealtimeCommand("deactivate");
    }
    setButtonPhase("idle");
  }

  function ensureFixedButton() {
    const existing = document.getElementById(FIXED_ID);
    if (existing) {
      run.btn = existing;
      return existing;
    }
    const btn = document.createElement("button");
    btn.type = "button";
    btn.id = FIXED_ID;
    btn.setAttribute("aria-label", "AI Mic");
    btn.setAttribute("aria-pressed", "false");
    document.body.appendChild(btn);
    run.btn = btn;
    setButtonPhase("idle");

    btn.addEventListener("click", async () => {
      try {
        if (run.phase === "active" || run.phase === "connecting") {
          await deactivateViaWidget();
          return;
        }
        await activateViaWidget();
      } catch (e) {
        setButtonPhase("idle");
      }
    });

    return btn;
  }

  try {
    window.addEventListener("message", (ev) => {
      try {
        if (!ev || ev.origin !== window.location.origin) return;
        const d = ev.data;
        if (!d || typeof d !== "object") return;
        if (d.type !== "mh_realtime_status") return;
        if (d.status === "activated") {
          clearActivateTimer();
          setButtonPhase("active");
          return;
        }
        if (d.status === "deactivated") {
          clearActivateTimer();
          setButtonPhase("idle");
          return;
        }
        if (d.status === "ready" && run.phase === "connecting") {
          setButtonPhase("active");
        }
      } catch (e) {}
    });
  } catch (e) {}

  setInterval(() => {
    try {
      cleanupEmbeddedButton();
      ensureFixedButton();
    } catch (e) {}
  }, 900);
})();
