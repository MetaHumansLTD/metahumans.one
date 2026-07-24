(() => {
  if (window.__mh_meet_persona_widget_bridge_v1) return;
  window.__mh_meet_persona_widget_bridge_v1 = true;
  if (window.top !== window.self) return;

  const MOUNT_ID = "mh-overlay-mount";
  const SCRIPT_ID = "mh-meet-overlay-widget";
  const LEGACY_ROOT_ID = "mh-meet-autobot";
  const LEGACY_LS_KEY = "mh_meet_autobot_persona_id";
  const WIDGET_LS_KEY = "mh_selected_persona";
  const WIDGET_SRC = "/hub/genesis/widget.js";

  function promoteLegacyPersona() {
    try {
      const widgetPersona = String(localStorage.getItem(WIDGET_LS_KEY) || "").trim();
      if (widgetPersona) return;
      const legacyPersona = String(localStorage.getItem(LEGACY_LS_KEY) || "").trim();
      if (legacyPersona) localStorage.setItem(WIDGET_LS_KEY, legacyPersona);
    } catch (e) {}
  }

  function removeLegacyUi() {
    try {
      const oldRoot = document.getElementById(LEGACY_ROOT_ID);
      if (oldRoot && oldRoot.parentNode) oldRoot.parentNode.removeChild(oldRoot);
    } catch (e) {}
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
      return mount;
    }

    if (!mount.getAttribute("data-hub-base")) mount.setAttribute("data-hub-base", "/hub");
    if (!mount.getAttribute("data-default-mode")) mount.setAttribute("data-default-mode", "auto");
    mount.setAttribute("data-autostart", "0");
    return mount;
  }

  function mountWidget() {
    try {
      const mount = ensureMount();
      if (!window.MetaHumansOverlayWidget || typeof window.MetaHumansOverlayWidget.mount !== "function") return;
      window.MetaHumansOverlayWidget.mount({
        hubBase: mount.getAttribute("data-hub-base") || "/hub",
        config: {
          wgt_metahuman_overlay_default_mode: mount.getAttribute("data-default-mode") || "auto",
        },
      });
    } catch (e) {}
  }

  function ensureWidgetScript() {
    if (window.MetaHumansOverlayWidget && typeof window.MetaHumansOverlayWidget.mount === "function") {
      mountWidget();
      return;
    }

    const existing = document.getElementById(SCRIPT_ID);
    if (existing) return;

    const s = document.createElement("script");
    s.id = SCRIPT_ID;
    s.src = WIDGET_SRC + "?v=" + Date.now();
    s.async = true;
    s.onload = () => {
      mountWidget();
    };
    (document.head || document.documentElement).appendChild(s);
  }

  function init() {
    promoteLegacyPersona();
    removeLegacyUi();
    ensureMount();
    ensureWidgetScript();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }

  setTimeout(init, 350);
})();
