(function () {
  var W = (function () {
    var state = {
      mounted: false,
      open: false,
      mountEl: null,
      rootEl: null,
      dockEl: null,
      overlayEl: null,
      panelEl: null,
      panelHeaderEl: null,
      panelResizeEl: null,
      styleEl: null,
      hubBase: "/hub",
      config: {},
      bootstrap: null,
      abort: null,
      realtimeFrameEl: null,
      realtimeCommand: null,
      realtimeStartSession: null,
    };
    var dockDrag = {
      active: false,
      pointerId: null,
      startX: 0,
      startY: 0,
      baseX: 0,
      baseY: 0,
      moved: false,
      suppressClick: false,
    };
    var panelDrag = {
      active: false,
      pointerId: null,
      startX: 0,
      startY: 0,
      baseX: 0,
      baseY: 0,
      moved: false,
    };
    var panelResize = {
      active: false,
      pointerId: null,
      startX: 0,
      startY: 0,
      baseX: 0,
      baseY: 0,
      baseW: 0,
      baseH: 0,
      dir: "",
    };

    function q(sel, root) {
      return (root || document).querySelector(sel);
    }

    function ce(tag, cls) {
      var el = document.createElement(tag);
      if (cls) el.className = cls;
      return el;
    }

    function setText(el, txt) {
      el.textContent = txt == null ? "" : String(txt);
    }

    function safeJson(res) {
      return res
        .text()
        .then(function (t) {
          try {
            return JSON.parse(t);
          } catch (e) {
            return { success: false, error: "invalid_json", raw: t };
          }
        })
        .catch(function () {
          return { success: false, error: "read_failed" };
        });
    }

    function isGenesisSignedAssetUrl(url) {
      var raw = url == null ? "" : String(url).trim();
      if (!raw) return false;
      if (raw.indexOf("/hub/genesis/asset.php") === -1) return true;
      try {
        var parsed = new URL(raw, window.location.origin);
        if (!/\/hub\/genesis\/asset\.php$/.test(parsed.pathname)) return true;
        return (
          parsed.searchParams.get("path") &&
          parsed.searchParams.get("exp") &&
          parsed.searchParams.get("sig")
        );
      } catch (e) {
        return false;
      }
    }

    function applyPreviewFrameUrl(frame, url) {
      var raw = url == null ? "" : String(url).trim();
      if (!raw) return false;
      if (!isGenesisSignedAssetUrl(raw)) return false;
      frame.src = raw;
      return true;
    }

    function api(path, opts) {
      var url = state.hubBase.replace(/\/+$/, "") + path;
      var o = opts || {};
      if (!o.headers) o.headers = {};
      if (!o.headers["Content-Type"] && o.method && o.method !== "GET") {
        o.headers["Content-Type"] = "application/json";
      }
      o.credentials = "include";
      if (state.abort) {
        try {
          state.abort.abort();
        } catch (e) {}
      }
      state.abort = new AbortController();
      o.signal = state.abort.signal;
      return fetch(url, o);
    }

    function ensureStyle() {
      if (state.styleEl) return;
      var st = ce("style");
      st.setAttribute("data-mh-overlay", "1");
      st.textContent =
        ".mh-overlay-root{position:fixed;inset:0;pointer-events:none;z-index:2147483647}" +
        ".mh-overlay-dock{position:fixed;pointer-events:auto;width:62px;height:62px;padding:0;border-radius:999px;display:flex;align-items:center;justify-content:center;background:radial-gradient(circle at 35% 35%,rgba(120,220,255,.55),rgba(10,30,70,.92) 60%,rgba(12,18,34,.95) 100%);border:1px solid rgba(255,215,120,.38);box-shadow:0 14px 40px rgba(0,0,0,.45),0 0 0 1px rgba(255,255,255,.10) inset;cursor:pointer;touch-action:none;user-select:none;-webkit-user-select:none;}" +
        ".mh-overlay-dock:active{transform:scale(.98)}" +
        ".mh-overlay-dock svg{width:54px;height:54px;display:block;pointer-events:none;}" +
        ".mh-overlay-backdrop{position:fixed;inset:0;display:none;pointer-events:auto;background:rgba(0,0,0,.55)}" +
        ".mh-overlay-backdrop.mh-overlay-open{display:block}" +
        ".mh-overlay-panel{position:fixed;right:20px;top:20px;bottom:20px;width:min(520px,calc(100vw - 40px));display:none;pointer-events:auto;background:rgba(12,18,34,.92);border:1px solid rgba(255,255,255,.14);border-radius:16px;backdrop-filter:blur(10px);color:#e8eefc;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;overflow:hidden}" +
        ".mh-overlay-panel.mh-overlay-open{display:flex;flex-direction:column}" +
        ".mh-overlay-h{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,.10);cursor:move;touch-action:none;user-select:none;-webkit-user-select:none;}" +
        ".mh-overlay-title{font-weight:950;font-size:12px;letter-spacing:.03em;text-transform:uppercase;opacity:.9}" +
        ".mh-overlay-close{margin-left:auto;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);color:#e8eefc;border-radius:10px;padding:6px 10px;font-weight:950;cursor:pointer}" +
        ".mh-overlay-b{padding:14px;display:flex;flex-direction:column;gap:12px;overflow:auto}" +
        ".mh-overlay-row{display:flex;flex-direction:column;gap:6px}" +
        ".mh-overlay-label{font-size:12px;opacity:.75;font-weight:900}" +
        ".mh-overlay-input,.mh-overlay-select{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);border-radius:10px;color:#e8eefc;padding:10px 12px;outline:none}" +
        ".mh-overlay-actions{display:flex;gap:10px;flex-wrap:wrap}" +
        ".mh-overlay-btn{background:rgba(0,212,255,.14);border:1px solid rgba(0,212,255,.35);color:#e8eefc;border-radius:10px;padding:10px 12px;font-weight:950;cursor:pointer}" +
        ".mh-overlay-btn2{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.18);color:#e8eefc;border-radius:10px;padding:10px 12px;font-weight:950;cursor:pointer}" +
        ".mh-overlay-note{font-size:12px;opacity:.8;line-height:1.4}" +
        ".mh-overlay-kv{font-size:12px;opacity:.9;word-break:break-word;white-space:pre-wrap}" +
        ".mh-overlay-kv a{color:#7ae8ff}" +
        ".mh-overlay-resizer{position:absolute;right:8px;bottom:8px;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.14);cursor:se-resize;pointer-events:auto;touch-action:none;}" +
        ".mh-overlay-resizer:before{content:'';position:absolute;right:6px;bottom:6px;width:10px;height:10px;border-right:2px solid rgba(255,255,255,.45);border-bottom:2px solid rgba(255,255,255,.45);border-radius:1px;opacity:.9}";
      document.head.appendChild(st);
      state.styleEl = st;
    }

    function dockStorageKey() {
      return "mh_overlay_dock_pos_v1";
    }

    function panelStorageKey() {
      return "mh_overlay_panel_v1";
    }

    function clamp(n, a, b) {
      n = Number(n);
      if (!isFinite(n)) return a;
      if (n < a) return a;
      if (n > b) return b;
      return n;
    }

    function getViewportSize() {
      try {
        var vv = window.visualViewport;
        if (vv && vv.width && vv.height) {
          return { w: vv.width, h: vv.height };
        }
      } catch (e) {}
      return { w: window.innerWidth || 0, h: window.innerHeight || 0 };
    }

    function getDockSize() {
      try {
        if (state.dockEl) {
          var r = state.dockEl.getBoundingClientRect();
          if (r && r.width && r.height) return { w: r.width, h: r.height };
        }
      } catch (e) {}
      return { w: 62, h: 62 };
    }

    function setDockPos(x, y, save) {
      if (!state.dockEl) return;
      var vp = getViewportSize();
      var ds = getDockSize();
      var margin = 10;
      var maxX = Math.max(margin, (vp.w || 0) - ds.w - margin);
      var maxY = Math.max(margin, (vp.h || 0) - ds.h - margin);
      var cx = clamp(x, margin, maxX);
      var cy = clamp(y, margin, maxY);
      state.dockEl.style.left = cx + "px";
      state.dockEl.style.top = cy + "px";
      state.dockEl.style.right = "auto";
      state.dockEl.style.bottom = "auto";
      if (save) {
        try {
          localStorage.setItem(dockStorageKey(), JSON.stringify({ x: cx, y: cy, t: Date.now() }));
        } catch (e) {}
      }
    }

    function loadDockPos() {
      try {
        var raw = localStorage.getItem(dockStorageKey());
        if (!raw) return null;
        var j = JSON.parse(raw);
        if (!j || typeof j !== "object") return null;
        if (!isFinite(Number(j.x)) || !isFinite(Number(j.y))) return null;
        return { x: Number(j.x), y: Number(j.y) };
      } catch (e) {
        return null;
      }
    }

    function setDefaultDockPos() {
      var vp = getViewportSize();
      var ds = getDockSize();
      var margin = 12;
      var x = Math.max(margin, (vp.w || 0) - ds.w - margin);
      var y = Math.max(margin, Math.round(((vp.h || 0) - ds.h) * 0.5));
      setDockPos(x, y, false);
    }

    function loadPanelLayout() {
      try {
        var raw = localStorage.getItem(panelStorageKey());
        if (!raw) return null;
        var j = JSON.parse(raw);
        if (!j || typeof j !== "object") return null;
        var x = Number(j.x);
        var y = Number(j.y);
        var w = Number(j.w);
        var h = Number(j.h);
        if (!isFinite(x) || !isFinite(y) || !isFinite(w) || !isFinite(h)) return null;
        return { x: x, y: y, w: w, h: h };
      } catch (e) {
        return null;
      }
    }

    function savePanelLayoutFromRect(rect) {
      try {
        localStorage.setItem(
          panelStorageKey(),
          JSON.stringify({ x: rect.left, y: rect.top, w: rect.width, h: rect.height, t: Date.now() })
        );
      } catch (e) {}
    }

    function applyPanelLayout(layout) {
      if (!state.panelEl || !layout) return;
      var vp = getViewportSize();
      var margin = 10;
      var minW = 320;
      var minH = 360;
      var maxW = Math.max(minW, (vp.w || 0) - margin * 2);
      var maxH = Math.max(minH, (vp.h || 0) - margin * 2);
      var w = clamp(layout.w, minW, maxW);
      var h = clamp(layout.h, minH, maxH);
      var maxX = Math.max(margin, (vp.w || 0) - w - margin);
      var maxY = Math.max(margin, (vp.h || 0) - h - margin);
      var x = clamp(layout.x, margin, maxX);
      var y = clamp(layout.y, margin, maxY);
      state.panelEl.style.left = x + "px";
      state.panelEl.style.top = y + "px";
      state.panelEl.style.right = "auto";
      state.panelEl.style.bottom = "auto";
      state.panelEl.style.width = w + "px";
      state.panelEl.style.height = h + "px";
    }

    function initPanelDragAndResize() {
      if (!state.panelEl || !state.panelHeaderEl || !state.panelResizeEl) return;

      function isCloseTarget(t) {
        try {
          if (!t) return false;
          if (t === state.panelResizeEl) return true;
          if (t.classList && t.classList.contains("mh-overlay-close")) return true;
          if (t.closest) {
            if (t.closest(".mh-overlay-close")) return true;
            if (t.closest(".mh-overlay-resizer")) return true;
          }
          return false;
        } catch (e) {
          return false;
        }
      }

      function getResizeDir(e, rect) {
        try {
          var t = 14;
          var left = e.clientX - rect.left;
          var right = rect.right - e.clientX;
          var top = e.clientY - rect.top;
          var bottom = rect.bottom - e.clientY;
          var h = "";
          var v = "";
          if (left >= 0 && left <= t) h = "w";
          else if (right >= 0 && right <= t) h = "e";
          if (top >= 0 && top <= t) v = "n";
          else if (bottom >= 0 && bottom <= t) v = "s";
          var dir = v + h;
          return dir ? dir : "";
        } catch (ex) {
          return "";
        }
      }

      function cursorForDir(dir) {
        if (!dir) return "";
        if (dir === "n" || dir === "s") return "ns-resize";
        if (dir === "e" || dir === "w") return "ew-resize";
        if (dir === "ne" || dir === "sw") return "nesw-resize";
        if (dir === "nw" || dir === "se") return "nwse-resize";
        return "";
      }

      function onHeaderDown(e) {
        try {
          if (isCloseTarget(e.target)) return;
          if (panelResize.active || panelDrag.active) return;
          var rect = state.panelEl.getBoundingClientRect();
          panelDrag.active = true;
          panelDrag.pointerId = e.pointerId;
          panelDrag.startX = e.clientX;
          panelDrag.startY = e.clientY;
          panelDrag.baseX = rect.left;
          panelDrag.baseY = rect.top;
          panelDrag.moved = false;
          state.panelEl.style.left = rect.left + "px";
          state.panelEl.style.top = rect.top + "px";
          state.panelEl.style.right = "auto";
          state.panelEl.style.bottom = "auto";
          state.panelEl.style.width = rect.width + "px";
          state.panelEl.style.height = rect.height + "px";
          try {
            state.panelHeaderEl.setPointerCapture(e.pointerId);
          } catch (ex) {}
        } catch (e2) {}
      }

      function onPanelDown(e) {
        try {
          if (isCloseTarget(e.target)) return;
          if (panelResize.active || panelDrag.active) return;
          var rect = state.panelEl.getBoundingClientRect();
          var dir = getResizeDir(e, rect);
          if (!dir) return;
          panelResize.active = true;
          panelResize.pointerId = e.pointerId;
          panelResize.startX = e.clientX;
          panelResize.startY = e.clientY;
          panelResize.baseX = rect.left;
          panelResize.baseY = rect.top;
          panelResize.baseW = rect.width;
          panelResize.baseH = rect.height;
          panelResize.dir = dir;
          state.panelEl.style.left = rect.left + "px";
          state.panelEl.style.top = rect.top + "px";
          state.panelEl.style.right = "auto";
          state.panelEl.style.bottom = "auto";
          state.panelEl.style.width = rect.width + "px";
          state.panelEl.style.height = rect.height + "px";
          state.panelEl.style.cursor = cursorForDir(dir) || "se-resize";
          try {
            state.panelEl.setPointerCapture(e.pointerId);
          } catch (ex) {}
          try {
            if (typeof e.preventDefault === "function") e.preventDefault();
          } catch (ex2) {}
        } catch (e2) {}
      }

      function onResizeDown(e) {
        try {
          if (panelResize.active || panelDrag.active) return;
          var rect = state.panelEl.getBoundingClientRect();
          panelResize.active = true;
          panelResize.pointerId = e.pointerId;
          panelResize.startX = e.clientX;
          panelResize.startY = e.clientY;
          panelResize.baseX = rect.left;
          panelResize.baseY = rect.top;
          panelResize.baseW = rect.width;
          panelResize.baseH = rect.height;
          panelResize.dir = "se";
          state.panelEl.style.left = rect.left + "px";
          state.panelEl.style.top = rect.top + "px";
          state.panelEl.style.right = "auto";
          state.panelEl.style.bottom = "auto";
          state.panelEl.style.width = rect.width + "px";
          state.panelEl.style.height = rect.height + "px";
          try {
            state.panelResizeEl.setPointerCapture(e.pointerId);
          } catch (ex) {}
        } catch (e2) {}
      }

      function onMove(e) {
        try {
          var vp = getViewportSize();
          var margin = 10;
          if (panelDrag.active) {
            if (panelDrag.pointerId != null && e.pointerId !== panelDrag.pointerId) return;
            var dx = e.clientX - panelDrag.startX;
            var dy = e.clientY - panelDrag.startY;
            if (!panelDrag.moved && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) panelDrag.moved = true;
            var rect = state.panelEl.getBoundingClientRect();
            var maxX = Math.max(margin, (vp.w || 0) - rect.width - margin);
            var maxY = Math.max(margin, (vp.h || 0) - rect.height - margin);
            var x = clamp(panelDrag.baseX + dx, margin, maxX);
            var y = clamp(panelDrag.baseY + dy, margin, maxY);
            state.panelEl.style.left = x + "px";
            state.panelEl.style.top = y + "px";
          } else if (panelResize.active) {
            if (panelResize.pointerId != null && e.pointerId !== panelResize.pointerId) return;
            var dx2 = e.clientX - panelResize.startX;
            var dy2 = e.clientY - panelResize.startY;
            var minW = 320;
            var minH = 360;
            var rightBase = panelResize.baseX + panelResize.baseW;
            var bottomBase = panelResize.baseY + panelResize.baseH;
            var dir = panelResize.dir || "se";
            var w = panelResize.baseW;
            var h = panelResize.baseH;
            var left = panelResize.baseX;
            var top = panelResize.baseY;

            if (dir.indexOf("e") !== -1) {
              var maxW = Math.max(minW, (vp.w || 0) - margin - panelResize.baseX);
              w = clamp(panelResize.baseW + dx2, minW, maxW);
            }
            if (dir.indexOf("s") !== -1) {
              var maxH = Math.max(minH, (vp.h || 0) - margin - panelResize.baseY);
              h = clamp(panelResize.baseH + dy2, minH, maxH);
            }
            if (dir.indexOf("w") !== -1) {
              var maxW2 = Math.max(minW, rightBase - margin);
              w = clamp(panelResize.baseW - dx2, minW, maxW2);
              left = rightBase - w;
              left = clamp(left, margin, rightBase - minW);
              w = rightBase - left;
            }
            if (dir.indexOf("n") !== -1) {
              var maxH2 = Math.max(minH, bottomBase - margin);
              h = clamp(panelResize.baseH - dy2, minH, maxH2);
              top = bottomBase - h;
              top = clamp(top, margin, bottomBase - minH);
              h = bottomBase - top;
            }

            state.panelEl.style.left = left + "px";
            state.panelEl.style.top = top + "px";
            state.panelEl.style.width = w + "px";
            state.panelEl.style.height = h + "px";
          }
          if (!panelResize.active && !panelDrag.active) {
            try {
              var rect3 = state.panelEl.getBoundingClientRect();
              var dir2 = getResizeDir(e, rect3);
              state.panelEl.style.cursor = dir2 ? cursorForDir(dir2) : "";
            } catch (ex2) {}
          }
        } catch (e3) {}
      }

      function onUp(e) {
        try {
          if (panelDrag.active) {
            if (panelDrag.pointerId != null && e.pointerId !== panelDrag.pointerId) return;
            panelDrag.active = false;
            try {
              state.panelHeaderEl.releasePointerCapture(e.pointerId);
            } catch (ex) {}
            var rect = state.panelEl.getBoundingClientRect();
            savePanelLayoutFromRect(rect);
          } else if (panelResize.active) {
            if (panelResize.pointerId != null && e.pointerId !== panelResize.pointerId) return;
            panelResize.active = false;
            try {
              state.panelResizeEl.releasePointerCapture(e.pointerId);
            } catch (ex2) {}
            var rect2 = state.panelEl.getBoundingClientRect();
            savePanelLayoutFromRect(rect2);
            try {
              state.panelEl.style.cursor = "";
            } catch (ex3) {}
          }
        } catch (e4) {}
      }

      state.panelHeaderEl.addEventListener("pointerdown", onHeaderDown);
      state.panelEl.addEventListener("pointerdown", onPanelDown);
      state.panelResizeEl.addEventListener("pointerdown", onResizeDown);
      window.addEventListener("pointermove", onMove);
      window.addEventListener("pointerup", onUp);
      window.addEventListener("pointercancel", onUp);

      state.panelEl.addEventListener("pointerleave", function () {
        try {
          if (!panelResize.active && !panelDrag.active) state.panelEl.style.cursor = "";
        } catch (e) {}
      });

      try {
        var applyClamp = function () {
          try {
            if (!state.panelEl) return;
            var rect = state.panelEl.getBoundingClientRect();
            var layout = { x: rect.left, y: rect.top, w: rect.width, h: rect.height };
            applyPanelLayout(layout);
          } catch (e) {}
        };
        window.addEventListener("resize", applyClamp);
        if (window.visualViewport) window.visualViewport.addEventListener("resize", applyClamp);
      } catch (e) {}
    }

    function initDockDrag() {
      if (!state.dockEl) return;

      function onPointerDown(e) {
        try {
          if (dockDrag.active) return;
          if (e && typeof e.button === "number" && e.button !== 0) return;
          var rect = state.dockEl.getBoundingClientRect();
          dockDrag.active = true;
          dockDrag.pointerId = e.pointerId;
          dockDrag.startX = e.clientX;
          dockDrag.startY = e.clientY;
          dockDrag.baseX = rect.left;
          dockDrag.baseY = rect.top;
          dockDrag.moved = false;
          try {
            state.dockEl.setPointerCapture(e.pointerId);
          } catch (ex) {}
        } catch (ex2) {}
      }

      function onPointerMove(e) {
        try {
          if (!dockDrag.active) return;
          if (dockDrag.pointerId != null && e.pointerId !== dockDrag.pointerId) return;
          var dx = e.clientX - dockDrag.startX;
          var dy = e.clientY - dockDrag.startY;
          if (!dockDrag.moved && (Math.abs(dx) > 3 || Math.abs(dy) > 3)) {
            dockDrag.moved = true;
            dockDrag.suppressClick = true;
          }
          setDockPos(dockDrag.baseX + dx, dockDrag.baseY + dy, false);
        } catch (ex) {}
      }

      function onPointerUp(e) {
        try {
          if (!dockDrag.active) return;
          if (dockDrag.pointerId != null && e.pointerId !== dockDrag.pointerId) return;
          dockDrag.active = false;
          try {
            state.dockEl.releasePointerCapture(e.pointerId);
          } catch (ex) {}
          if (dockDrag.moved) {
            var rect = state.dockEl.getBoundingClientRect();
            setDockPos(rect.left, rect.top, true);
            setTimeout(function () {
              dockDrag.suppressClick = false;
            }, 350);
          }
        } catch (ex2) {}
      }

      state.dockEl.addEventListener("pointerdown", onPointerDown);
      window.addEventListener("pointermove", onPointerMove);
      window.addEventListener("pointerup", onPointerUp);
      window.addEventListener("pointercancel", onPointerUp);

      try {
        window.addEventListener("resize", function () {
          try {
            if (!state.dockEl) return;
            var rect = state.dockEl.getBoundingClientRect();
            setDockPos(rect.left, rect.top, false);
          } catch (e) {}
        });
      } catch (e) {}
      try {
        if (window.visualViewport) {
          window.visualViewport.addEventListener("resize", function () {
            try {
              if (!state.dockEl) return;
              var rect = state.dockEl.getBoundingClientRect();
              setDockPos(rect.left, rect.top, false);
            } catch (e) {}
          });
        }
      } catch (e) {}
    }

    function renderSkeleton() {
      var root = ce("div", "mh-overlay-root");
      var dock = ce("button", "mh-overlay-dock");
      dock.type = "button";
      dock.setAttribute("aria-label", "MetaHumans");
      dock.innerHTML =
        '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">' +
        '<defs>' +
        '<radialGradient id="mh_glass" cx="38%" cy="28%" r="70%"><stop offset="0" stop-color="#c9fbff" stop-opacity="0.95"/><stop offset="0.55" stop-color="#3cc9ff" stop-opacity="0.55"/><stop offset="1" stop-color="#031128" stop-opacity="0.12"/></radialGradient>' +
        '<linearGradient id="mh_gold" x1="0" x2="1"><stop offset="0" stop-color="#fff2bf"/><stop offset="0.45" stop-color="#f2c14d"/><stop offset="1" stop-color="#a86a14"/></linearGradient>' +
        '<radialGradient id="mh_glow" cx="50%" cy="45%" r="60%"><stop offset="0" stop-color="#ffffff" stop-opacity="0.20"/><stop offset="1" stop-color="#ffffff" stop-opacity="0"/></radialGradient>' +
        '</defs>' +
        '<circle cx="32" cy="24" r="15" fill="url(#mh_glass)" stroke="url(#mh_gold)" stroke-width="2.2"/>' +
        '<circle cx="32" cy="24" r="13.5" fill="url(#mh_glow)"/>' +
        '<path d="M32 20c3.1 0 5.6 2.5 5.6 5.6S35.1 31.2 32 31.2s-5.6-2.5-5.6-5.6S28.9 20 32 20z" fill="#0b132a" fill-opacity="0.55"/>' +
        '<path d="M23 39c2.8-4.3 6.1-6.4 9-6.4s6.2 2.1 9 6.4c-2.8 1.5-5.9 2.3-9 2.3s-6.2-.8-9-2.3z" fill="#0b132a" fill-opacity="0.52"/>' +
        '<path d="M10 46c7.4-1.4 12.4-6.4 16-10.7-1.6 6.6-4.5 14-11.2 17.6-2.2 1.2-3.8 1.7-4.8 1.8 0-3 .1-5.6 0-8.7z" fill="url(#mh_gold)" fill-opacity="0.92"/>' +
        '<path d="M54 46c-7.4-1.4-12.4-6.4-16-10.7 1.6 6.6 4.5 14 11.2 17.6 2.2 1.2 3.8 1.7 4.8 1.8 0-3-.1-5.6 0-8.7z" fill="url(#mh_gold)" fill-opacity="0.92"/>' +
        '<path d="M18 52c7.6-1.4 11.2-6.2 14-11.7 2.8 5.5 6.4 10.3 14 11.7-3.6 3.3-7.8 4.8-14 4.8S21.6 55.3 18 52z" fill="url(#mh_gold)" fill-opacity="0.98"/>' +
        '</svg>';
      dock.addEventListener("click", function () {
        if (dockDrag.suppressClick) return;
        toggle();
      });

      var backdrop = ce("div", "mh-overlay-backdrop");
      backdrop.addEventListener("click", function () {
        close();
      });

      var panel = ce("div", "mh-overlay-panel");
      var h = ce("div", "mh-overlay-h");
      var title = ce("div", "mh-overlay-title");
      setText(title, "");
      var closeBtn = ce("button", "mh-overlay-close");
      closeBtn.type = "button";
      setText(closeBtn, "Close");
      closeBtn.addEventListener("click", function () {
        close();
      });
      h.appendChild(title);
      h.appendChild(closeBtn);

      var body = ce("div", "mh-overlay-b");

      panel.appendChild(h);
      panel.appendChild(body);
      var resizer = ce("div", "mh-overlay-resizer");
      panel.appendChild(resizer);

      root.appendChild(dock);
      root.appendChild(backdrop);
      root.appendChild(panel);

      state.rootEl = root;
      state.dockEl = dock;
      state.overlayEl = backdrop;
      state.panelEl = panel;
      state.panelHeaderEl = h;
      state.panelResizeEl = resizer;

      state.mountEl.appendChild(root);
      return body;
    }

    function renderBody(body) {
      body.innerHTML = "";

      var personaRow = ce("div", "mh-overlay-row");
      var personaLabel = ce("div", "mh-overlay-label");
      setText(personaLabel, "Persona");
      var personaSel = ce("select", "mh-overlay-select");
      personaSel.name = "persona";
      personaRow.appendChild(personaLabel);
      personaRow.appendChild(personaSel);

      var personaPreview = ce("img");
      personaPreview.alt = "Persona Avatar";
      personaPreview.style.cssText =
        "width:100%;aspect-ratio:1/1;border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(0,0,0,.35);display:none;object-fit:contain;";
      personaRow.appendChild(personaPreview);

      var frame = ce("iframe");
      frame.setAttribute("allow", "autoplay; microphone; camera; fullscreen");
      frame.setAttribute("referrerpolicy", "no-referrer-when-downgrade");
      frame.style.cssText =
        "width:100%;aspect-ratio:1/1;border:1px solid rgba(255,255,255,.12);border-radius:12px;background:rgba(0,0,0,.35);display:none;";
      personaRow.appendChild(frame);

      state.realtimeFrameEl = frame;
      state.realtimeCommand = function (cmd) {
        try {
          if (!state.realtimeFrameEl || !state.realtimeFrameEl.contentWindow) return;
          state.realtimeFrameEl.contentWindow.postMessage({ type: "mh_realtime_command", cmd: String(cmd || "") }, window.location.origin);
        } catch (e) {}
      };

      try {
        frame.addEventListener("load", function () {
          setStatus("Persona ready.");
          try { if (state.open && state.realtimeCommand) state.realtimeCommand("activate"); } catch (e) {}
        });
      } catch (e) {}
      try {
        frame.addEventListener("error", function () {
          setStatus("Persona failed to load.");
        });
      } catch (e) {}

      try {
        if (!window.__mh_realtime_status_listener) {
          window.__mh_realtime_status_listener = true;
          window.addEventListener("message", function (ev) {
            try {
              if (!ev || ev.origin !== window.location.origin) return;
              var d = ev.data;
              if (!d || typeof d !== "object") return;
              if (d.type !== "mh_realtime_status") return;
              if (d.status === "ready") setStatus("Persona ready.");
            } catch (e) {}
          });
        }
      } catch (e) {}


      var modeRow = ce("div", "mh-overlay-row");
      var modeLabel = ce("div", "mh-overlay-label");
      setText(modeLabel, "Mode");
      var modeSel = ce("select", "mh-overlay-select");
      modeSel.name = "mode";
      ["auto", "realtime", "voice_text", "text_only"].forEach(function (m) {
        var opt = ce("option");
        opt.value = m;
        opt.textContent = m;
        modeSel.appendChild(opt);
      });
      modeRow.appendChild(modeLabel);
      modeRow.appendChild(modeSel);

      var actions = ce("div", "mh-overlay-actions");
      var btnBootstrap = ce("button", "mh-overlay-btn2");
      btnBootstrap.type = "button";
      setText(btnBootstrap, "Refresh");
      var btnStart = ce("button", "mh-overlay-btn");
      btnStart.type = "button";
      setText(btnStart, "Start Session");
      actions.appendChild(btnStart);
      actions.appendChild(btnBootstrap);

      var info = ce("div", "mh-overlay-row");
      var infoText = ce("div", "mh-overlay-kv");
      setText(infoText, "Ready.");
      info.appendChild(infoText);

      body.appendChild(personaRow);
      body.appendChild(modeRow);
      body.appendChild(actions);
      body.appendChild(info);

      var chat = ce("div", "mh-overlay-row");
      var chatLabel = ce("div", "mh-overlay-label");
      setText(chatLabel, "Chat");
      var chatOut = ce("div", "mh-overlay-kv");
      setText(chatOut, "");
      chat.appendChild(chatLabel);
      chat.appendChild(chatOut);
      body.appendChild(chat);

      function setChatVisible(v) {
        try { chat.style.display = v ? "" : "none"; } catch (e) {}
      }


      function setStatus(html) {
        infoText.innerHTML = html;
      }

      function isRealtimeMode() {
        var m = modeSel && modeSel.value ? String(modeSel.value) : "";
        return m === "realtime" || m === "auto";
      }

      function persistPersona(pid) {
        var p = (pid || "").trim();
        if (!p) return;
        try { localStorage.setItem("mh_selected_persona", p); } catch (e) {}
        try {
          var u = state.hubBase.replace(/\/+$/, "") + "/widget/bootstrap/?set_persona=" + encodeURIComponent(p);
          state.lastPersistedPersona = p;
          if (navigator && typeof navigator.sendBeacon === "function") {
            var body = new URLSearchParams();
            body.set("set_persona", p);
            navigator.sendBeacon(state.hubBase.replace(/\/+$/, "") + "/widget/bootstrap/", body);
          } else {
            fetch(u, { credentials: "include", keepalive: true }).catch(function () {});
          }
        } catch (e) {}
      }

      function loadBootstrap() {
        setStatus("Loading bootstrap…");
        var storedPersona = "";
        try { storedPersona = localStorage.getItem("mh_selected_persona") || ""; } catch (e) {}
        var u = state.hubBase.replace(/\/+$/, "") + "/widget/bootstrap/";
        if (storedPersona) u += "?set_persona=" + encodeURIComponent(storedPersona);
        return fetch(u, { method: "GET", credentials: "include" })
          .then(function (res) {
            return safeJson(res);
          })
          .then(function (data) {
            if (!data || data.success !== true) {
              if (data && data.login_url) {
                try { window.location.href = data.login_url; } catch (e) {}
                setStatus('Login required: <a href="' + data.login_url + '">login</a>');
                return null;
              }
              setStatus("Bootstrap failed.");
              return null;
            }
            state.bootstrap = data;
            var personas = Array.isArray(data.personas) ? data.personas : [];
            personaSel.innerHTML = "";
            personas.forEach(function (p) {
              var opt = ce("option");
              opt.value = p.id || "";
              opt.textContent = p.name || p.id || "";
              if (p.selected) opt.selected = true;
              personaSel.appendChild(opt);
            });
            state.bootstrapPersonas = personas;
            var storedPersona2 = "";
            try { storedPersona2 = localStorage.getItem("mh_selected_persona") || ""; } catch (e) {}
            if (storedPersona2) { try { personaSel.value = storedPersona2; } catch (e) {} }
            if ((personaSel.value || "") !== (state.lastPersistedPersona || "")) persistPersona(personaSel.value || "");
            var defMode =
              (state.config && state.config.wgt_metahuman_overlay_default_mode) || "auto";
            modeSel.value = defMode;
            setStatus("Ready.");
              updatePersonaPreview();
              try { loadChatHistory(personaSel.value || ""); } catch (e) {}
              try { if (isRealtimeMode()) setChatVisible(false); else setChatVisible(true); } catch (e) {}
              try { if (isRealtimeMode()) startSession(); } catch (e) {}
              return data;
          })
          .catch(function () {
            setStatus("Bootstrap error.");
            return null;
          });
      }

      function updatePersonaPreview() {
        var id = personaSel.value || "";
        var list = state.bootstrapPersonas || [];
        var found = null;
        for (var i = 0; i < list.length; i++) {
          if ((list[i] && list[i].id) === id) {
            found = list[i];
            break;
          }
        }
        var url = found && found.avatar_url ? String(found.avatar_url) : "";
        if (!url) {
          personaPreview.style.display = "none";
          personaPreview.removeAttribute("src");
          state.lastPreviewUrl = "";
          return;
        }
        if (!frame || frame.style.display === "none") { personaPreview.style.display = "block"; } else { personaPreview.style.display = "none"; }
        if (state.lastPreviewUrl === url) {
          return;
        }
        state.lastPreviewUrl = url;
        personaPreview.src = url;
      }
      function loadChatHistory(personaId) {
        var pid = personaId || "";
        if (!pid) {
          setText(chatOut, "");
          return;
        }
        var u = state.hubBase.replace(/\/+$/, "") + "/widget/persona/chat/?action=history&limit=40&persona_id=" + encodeURIComponent(pid);
        fetch(u, { credentials: "include" })
          .then(function (res) { return safeJson(res); })
          .then(function (data) {
            if (!data || data.success !== true || !Array.isArray(data.events)) {
              setText(chatOut, "");
              return;
            }
            var out = [];
            data.events.forEach(function (ev) {
              if (!ev || typeof ev !== "object") return;
              var kind = String(ev.kind || "").toLowerCase();
              var text = String(ev.text || "").trim();
              if (!text) return;
              if (kind === "user") out.push("You: " + text);
              if (kind === "assistant") out.push("Persona: " + text);
            });
            setText(chatOut, out.join("\n"));
          })
          .catch(function () {
            setText(chatOut, "");
          });
      }


      function startSession() {
        var personaId = personaSel.value || "";
        var mode = modeSel.value || "auto";
        if (!personaId) {
          setStatus("Choose a persona first.");
          return;
        }
        if (personaId !== (state.lastPersistedPersona || "")) persistPersona(personaId);
        setStatus("Starting session…");
        try {
          frame.src = "";
          frame.style.display = "none";
        } catch (e) {}
        try {
          if (personaPreview && personaPreview.src) personaPreview.style.display = "block";
        } catch (e) {}

        if (mode === "realtime" || mode === "auto") {
          setChatVisible(false);
          var rt = state.hubBase.replace(/\/+$/, "") + "/genesis/realtime.php?embed=1&view=avatar&persona_id=" + encodeURIComponent(personaId);
          frame.src = rt;
          frame.style.display = "block";
          try { personaPreview.style.display = "none"; } catch (e) {}
          setStatus("Connecting persona…");
          return;
        }

        setChatVisible(true);

        api("/widget/persona/render/", {
          method: "POST",
          body: JSON.stringify({
            persona_id: personaId,
            text: "Hello. I am your persona.",
          }),
        })
          .then(function (res) {
            return safeJson(res);
          })
          .then(function (data) {
            if (!data || data.success !== true) {
              setStatus("Render start failed.");
              return;
            }
            if (data.video_url) {
              if (!applyPreviewFrameUrl(frame, data.video_url)) {
                setStatus("Preview URL invalid.");
                return;
              }
              frame.style.display = "block";
              personaPreview.style.display = "none";
              setStatus("Preview ready.");
              return;
            }
            var pollUrl = data.poll_url || "";
            if (!pollUrl) {
              setStatus("Render queued.");
              return;
            }
            var tries = 0;
            function poll() {
              tries++;
              api(pollUrl.replace(state.hubBase.replace(/\/+$/, ""), ""), { method: "GET" })
                .then(function (r) { return safeJson(r); })
                .then(function (j) {
                  if (j && j.success === true && j.video_url) {
                    if (!applyPreviewFrameUrl(frame, j.video_url)) {
                      setStatus("Preview URL invalid.");
                      return;
                    }
                    frame.style.display = "block";
                    setStatus("Preview ready.");
                    return;
                  }
                  if (tries > 40) {
                    setStatus("Preview still running.");
                    return;
                  }
                  setStatus("Rendering…");
                  setTimeout(poll, 1500);
                })
                .catch(function () {
                  if (tries > 40) {
                    setStatus("Preview still running.");
                    return;
                  }
                  setStatus("Rendering…");
                  setTimeout(poll, 1500);
                });
            }
            setStatus("Rendering…");
            setTimeout(poll, 800);
          })
          .catch(function () {
            setStatus("Render error.");
          });
      }

      personaSel.addEventListener("change", function () {
        persistPersona(personaSel.value || "");
        updatePersonaPreview();
        loadChatHistory(personaSel.value || "");
        if (isRealtimeMode()) { startSession(); }
      });

      modeSel.addEventListener("change", function () {
        if (isRealtimeMode()) {
          try { if (typeof setChatVisible === "function") setChatVisible(false); } catch (e) {}
          startSession();
          return;
        }
        try { if (typeof setChatVisible === "function") setChatVisible(true); } catch (e) {}
        try { frame.src = ""; frame.style.display = "none"; } catch (e) {}
        updatePersonaPreview();
        setStatus("Ready.");
      });

      btnBootstrap.addEventListener("click", function () {
        loadBootstrap();
      });
      btnStart.addEventListener("click", function () {
        startSession();
      });

      try { state.realtimeStartSession = startSession; } catch (e) {}

      loadBootstrap();
    }

    function mount(options) {
      if (state.mounted) return;
      ensureStyle();
      state.mountEl = q("#mh-overlay-mount");
      if (!state.mountEl) return;
      state.hubBase = (options && options.hubBase) || state.mountEl.getAttribute("data-hub-base") || "/hub";
      state.config = (options && options.config) || {};
      var body = renderSkeleton();
      try {
        var saved = loadDockPos();
        if (saved) setDockPos(saved.x, saved.y, false);
        else setDefaultDockPos();
        initDockDrag();
        var savedPanel = loadPanelLayout();
        if (savedPanel) applyPanelLayout(savedPanel);
        initPanelDragAndResize();
      } catch (e) {}
      renderBody(body);
      state.mounted = true;
    }

    function unmount() {
      if (!state.mounted) return;
      try {
        if (state.abort) state.abort.abort();
      } catch (e) {}
      if (state.rootEl && state.rootEl.parentNode) state.rootEl.parentNode.removeChild(state.rootEl);
      if (state.styleEl && state.styleEl.parentNode) state.styleEl.parentNode.removeChild(state.styleEl);
      state.mounted = false;
      state.open = false;
      state.rootEl = null;
      state.overlayEl = null;
      state.panelEl = null;
      state.styleEl = null;
    }

    function open() {
      if (!state.mounted) mount({});
      if (!state.overlayEl || !state.panelEl) return;
      state.open = true;
      state.overlayEl.classList.add("mh-overlay-open");
      state.panelEl.classList.add("mh-overlay-open");
      try { if (state.realtimeStartSession) state.realtimeStartSession(); } catch (e) {}
      try { if (state.realtimeCommand) state.realtimeCommand("activate"); } catch (e) {}
    }

    function close() {
      if (!state.overlayEl || !state.panelEl) return;
      state.open = false;
      state.overlayEl.classList.remove("mh-overlay-open");
      state.panelEl.classList.remove("mh-overlay-open");
      try { if (state.realtimeCommand) state.realtimeCommand("deactivate"); } catch (e) {}
    }

    function toggle() {
      if (state.open) close();
      else open();
    }

    return {
      mount: mount,
      unmount: unmount,
      open: open,
      close: close,
      toggle: toggle,
    };
  })();

  window.MetaHumansOverlayWidget = W;

  try {
    var mountEl = document.getElementById("mh-overlay-mount");
    if (mountEl) {
      var hubBase = mountEl.getAttribute("data-hub-base") || "/hub";
      var autostart = mountEl.getAttribute("data-autostart");
      var cfg = {};
      try {
        cfg.wgt_metahuman_overlay_default_mode = mountEl.getAttribute("data-default-mode") || "auto";
      } catch (e) {}
      W.mount({ hubBase: hubBase, config: cfg });
      if (autostart === "1") {
        setTimeout(function () {
          try {
            W.open();
          } catch (e) {}
        }, 250);
      }
    }
  } catch (e) {}
})();
