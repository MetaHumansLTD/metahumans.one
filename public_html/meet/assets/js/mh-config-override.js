(() => {
  const cfg = (window.plugNmeetConfig && typeof window.plugNmeetConfig === "object") ? window.plugNmeetConfig : {};
  window.plugNmeetConfig = cfg;

  if (!cfg.serverUrl) cfg.serverUrl = window.location.origin;
  cfg.enableDynacast = true;
  cfg.enableSimulcast = true;
  cfg.videoCodec = "vp8";
  cfg.defaultWebcamResolution = "h540";
  cfg.defaultScreenShareResolution = "h1080fps15";
  cfg.defaultAudioPreset = "speech";
})();

