(function () {
  if (window.MHViewers && typeof window.MHViewers.openPdf === 'function') return;

  function ensure() {
    if (document.getElementById('mhPdfViewerModal')) return;

    var style = document.createElement('style');
    style.id = 'mhPdfViewerStyle';
    style.textContent = [
      '.mh-pdf-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:10060;padding:18px;align-items:center;justify-content:center;}',
      '.mh-pdf-card{max-width:1100px;width:100%;background:rgba(20,20,25,.96);border:1px solid rgba(0,212,255,.25);border-radius:16px;padding:14px;box-shadow:0 18px 60px rgba(0,0,0,.55);}',
      '.mh-pdf-head{display:flex;justify-content:space-between;align-items:center;gap:12px;}',
      '.mh-pdf-title{font-family:Orbitron,sans-serif;color:var(--theme-primary,#00d4ff);font-weight:900;letter-spacing:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
      '.mh-pdf-close{cursor:pointer;background:transparent;color:var(--theme-primary,#00d4ff);border:1px solid rgba(0,212,255,.45);border-radius:12px;padding:10px 12px;font-weight:900;letter-spacing:1px;}',
      '.mh-pdf-frame{width:100%;height:78vh;border:0;border-radius:12px;background:rgba(0,0,0,.35);margin-top:12px;}',
      '@media (max-width: 700px){.mh-pdf-frame{height:66vh;}}'
    ].join('');
    document.head.appendChild(style);

    var modal = document.createElement('div');
    modal.id = 'mhPdfViewerModal';
    modal.className = 'mh-pdf-modal';

    var card = document.createElement('div');
    card.className = 'mh-pdf-card';

    var head = document.createElement('div');
    head.className = 'mh-pdf-head';

    var title = document.createElement('div');
    title.id = 'mhPdfViewerTitle';
    title.className = 'mh-pdf-title';
    title.textContent = 'Document';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.id = 'mhPdfViewerClose';
    closeBtn.className = 'mh-pdf-close';
    closeBtn.textContent = 'Close';

    var frame = document.createElement('iframe');
    frame.id = 'mhPdfViewerFrame';
    frame.className = 'mh-pdf-frame';
    frame.setAttribute('loading', 'lazy');
    frame.setAttribute('referrerpolicy', 'no-referrer');

    head.appendChild(title);
    head.appendChild(closeBtn);
    card.appendChild(head);
    card.appendChild(frame);
    modal.appendChild(card);
    document.body.appendChild(modal);

    function close() {
      modal.style.display = 'none';
      try { frame.removeAttribute('src'); } catch (e) {}
    }

    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.style.display !== 'none') close(); });
  }

  function openPdf(url, opts) {
    ensure();
    var modal = document.getElementById('mhPdfViewerModal');
    var titleEl = document.getElementById('mhPdfViewerTitle');
    var frame = document.getElementById('mhPdfViewerFrame');
    if (!modal || !frame) return;

    var title = (opts && typeof opts.title === 'string') ? opts.title : 'Document';
    if (titleEl) titleEl.textContent = title;

    var src = (typeof url === 'string') ? url.trim() : '';
    if (!src) return;

    var withHash = src.indexOf('#') >= 0 ? src : (src + '#view=FitH');
    frame.setAttribute('src', withHash);
    modal.style.display = 'flex';
  }

  window.MHViewers = window.MHViewers || {};
  window.MHViewers.openPdf = openPdf;
})();
