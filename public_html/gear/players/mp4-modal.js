(function () {
  if (window.MHPlayers && typeof window.MHPlayers.openMp4 === 'function') return;

  var playbackToken = 0;

  function ensure() {
    if (document.getElementById('mhMp4PlayerModal')) return;

    var style = document.createElement('style');
    style.id = 'mhMp4PlayerStyle';
    style.textContent = [
      '.mh-mp4-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.76);z-index:10050;padding:18px;align-items:center;justify-content:center;}',
      '.mh-mp4-card{max-width:1000px;width:100%;background:rgba(20,20,25,.96);border:1px solid rgba(0,212,255,.25);border-radius:16px;padding:14px;}',
      '.mh-mp4-head{display:flex;justify-content:space-between;align-items:center;gap:12px;}',
      '.mh-mp4-title{font-family:Orbitron,sans-serif;color:var(--theme-primary,#00d4ff);font-weight:900;letter-spacing:1px;}',
      '.mh-mp4-close{cursor:pointer;background:transparent;color:var(--theme-primary,#00d4ff);border:1px solid var(--theme-primary,#00d4ff);border-radius:10px;padding:10px 12px;font-weight:800;letter-spacing:1px;}',
      '.mh-mp4-video{width:100%;height:74vh;border:0;border-radius:12px;background:rgba(0,0,0,.35);margin-top:12px;object-fit:contain;}',
      '@media (max-width: 700px){.mh-mp4-video{height:58vh;}}'
    ].join('');
    document.head.appendChild(style);

    var modal = document.createElement('div');
    modal.id = 'mhMp4PlayerModal';
    modal.className = 'mh-mp4-modal';

    var card = document.createElement('div');
    card.className = 'mh-mp4-card';

    var head = document.createElement('div');
    head.className = 'mh-mp4-head';

    var title = document.createElement('div');
    title.id = 'mhMp4PlayerTitle';
    title.className = 'mh-mp4-title';
    title.textContent = 'Video';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.id = 'mhMp4PlayerClose';
    closeBtn.className = 'mh-mp4-close';
    closeBtn.textContent = 'Close';

    var video = document.createElement('video');
    video.id = 'mhMp4PlayerVideo';
    video.className = 'mh-mp4-video';
    video.controls = true;
    video.playsInline = true;
    video.preload = 'metadata';

    head.appendChild(title);
    head.appendChild(closeBtn);
    card.appendChild(head);
    card.appendChild(video);
    modal.appendChild(card);
    document.body.appendChild(modal);

    function close() {
      playbackToken += 1;
      modal.style.display = 'none';
      try {
        video.pause();
      } catch (e) {}
      try {
        video.removeAttribute('src');
        video.load();
      } catch (e) {}
    }

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.style.display !== 'none') close();
    });
  }

  function openMp4(url, opts) {
    ensure();
    var modal = document.getElementById('mhMp4PlayerModal');
    var titleEl = document.getElementById('mhMp4PlayerTitle');
    var video = document.getElementById('mhMp4PlayerVideo');
    if (!modal || !video) return;

    var title = (opts && typeof opts.title === 'string') ? opts.title : 'Video';
    if (titleEl) titleEl.textContent = title;

    var src = (typeof url === 'string') ? url.trim() : '';
    if (!src) return;

    try {
      if (/\/information\/videos\/[^?#]+\.mp4(\?|#|$)/i.test(src) && !/[?&]raw=1(?:&|$)/.test(src)) {
        src += (src.indexOf('?') >= 0 ? '&' : '?') + 'raw=1';
      } else if (/\/information\/videos\/(?:\?|$)/i.test(src) && /[?&]f=/.test(src) && !/[?&]raw=1(?:&|$)/.test(src)) {
        src += (src.indexOf('?') >= 0 ? '&' : '?') + 'raw=1';
      }
    } catch (e) {}

    playbackToken += 1;
    var token = playbackToken;
    try {
      video.pause();
    } catch (e) {}
    video.setAttribute('src', src);
    modal.style.display = 'flex';
    try { video.load(); } catch (e) {}
    try {
      var playPromise = video.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(function (err) {
          if (token !== playbackToken) return;
          if (err && err.name === 'AbortError') return;
          if (window.console && typeof window.console.warn === 'function') {
            console.warn('MHPlayers.openMp4 play() failed', err);
          }
        });
      }
    } catch (e) {
      if (!e || e.name !== 'AbortError') {
        if (window.console && typeof window.console.warn === 'function') {
          console.warn('MHPlayers.openMp4 play() threw', e);
        }
      }
    }
  }

  window.MHPlayers = window.MHPlayers || {};
  window.MHPlayers.openMp4 = openMp4;
})();
