<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/tools/document-viewer.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/tools/document-viewer.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$baseDir = dirname(__DIR__, 2);
$docDir = $baseDir . '/information/documents';
$docs = [];

if (is_dir($docDir)) {
    $items = @scandir($docDir);
    if (is_array($items)) {
        foreach ($items as $f) {
            if (!is_string($f) || $f === '' || $f[0] === '.') continue;
            $full = $docDir . '/' . $f;
            if (!is_file($full)) continue;
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if ($ext !== 'pdf') continue;
            $url = '/information/documents/' . rawurlencode($f);
            $label = preg_replace('/\\.[^.]+$/', '', $f);
            $label = str_replace(['_', '-'], ' ', (string)$label);
            $label = trim(preg_replace('/\\s+/', ' ', (string)$label));
            $docs[] = [
                'file' => $f,
                'label' => $label !== '' ? $label : $f,
                'url' => $url,
                'ext' => $ext,
                'mtime' => @filemtime($full) ?: 0,
                'size' => @filesize($full) ?: 0,
            ];
        }
    }
}

usort($docs, function ($a, $b) {
    $am = (int)($a['mtime'] ?? 0);
    $bm = (int)($b['mtime'] ?? 0);
    if ($am !== $bm) return $bm <=> $am;
    return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
});

$pdfViewerPath = $baseDir . '/gear/viewers/pdf-modal.js';
$pdfViewerV = is_file($pdfViewerPath) ? (int)filemtime($pdfViewerPath) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Viewer</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.doc-viewer main.main-content { padding: 40px 0; }
        body.doc-viewer .wrap { max-width: 1100px; margin: 0 auto; padding: 0 24px; box-sizing: border-box; }
        body.doc-viewer h1 { margin: 0 0 8px 0; font-family: 'Orbitron', sans-serif; letter-spacing: 1px; }
        body.doc-viewer .hint { color: rgba(255,255,255,0.7); margin-bottom: 16px; }
        body.doc-viewer .controls { display:flex; gap: 12px; flex-wrap: wrap; align-items:center; margin: 14px 0 18px; }
        body.doc-viewer .controls input[type="search"] { width: 320px; max-width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color: #fff; }
        body.doc-viewer .controls select { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color: #fff; }
        body.doc-viewer .controls .meta { margin-left: auto; color: rgba(255,255,255,0.65); font-size: 0.95rem; }
        body.doc-viewer .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        body.doc-viewer .card { background: rgba(0, 212, 255, 0.05); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 14px; min-width: 0; cursor: pointer; }
        body.doc-viewer .thumb { height: 200px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.10); background: radial-gradient(800px 220px at 30% 10%, rgba(0,212,255,0.10), rgba(0,0,0,0)), rgba(0,0,0,0.25); display:flex; align-items:center; justify-content:center; position: relative; overflow:hidden; }
        body.doc-viewer .thumb-pdf { position:absolute; inset:0; width:100%; height:100%; border:0; background:#fff; pointer-events:none; }
        body.doc-viewer .thumb .shade { position:absolute; inset:0; background: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.45)); pointer-events:none; }
        body.doc-viewer .ext { position: relative; z-index: 1; font-family: 'Orbitron', sans-serif; font-weight: 900; letter-spacing: 1px; color: rgba(255,255,255,0.90); border: 1px solid rgba(0,212,255,0.35); background: rgba(0,0,0,0.25); padding: 6px 10px; border-radius: 999px; }
        body.doc-viewer .title { margin: 12px 0 6px 0; font-weight: 900; color: rgba(255,255,255,0.92); overflow-wrap:anywhere; }
        body.doc-viewer .sub { color: rgba(255,255,255,0.65); font-size: 0.9rem; display:flex; justify-content:space-between; gap: 10px; flex-wrap: wrap; }
        body.doc-viewer .btnrow { margin-top: 10px; display:flex; gap: 10px; flex-wrap: wrap; }
        body.doc-viewer .btn { width: auto; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--theme-primary, #00d4ff); background: var(--theme-primary, #00d4ff); color: #001018; font-weight: 900; letter-spacing: 0.5px; cursor:pointer; text-decoration:none; display:inline-block; }
        body.doc-viewer .btn.secondary { background: transparent; color: var(--theme-primary, #00d4ff); }
        @media (max-width: 520px) { body.doc-viewer .controls input[type="search"] { width: 100%; } body.doc-viewer .controls .meta { margin-left: 0; width: 100%; } }
    </style>
</head>
<body class="doc-viewer">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="wrap">
        <h1>DOCUMENT VIEWER</h1>
        <div class="hint">Browse documents in <code>/information/documents</code>. Use search and display count.</div>

        <div class="controls">
            <input id="mhDocSearch" type="search" placeholder="Search by name..." />
            <select id="mhDocLimit">
                <option value="3">Show 3</option>
                <option value="9">Show 9</option>
                <option value="12" selected>Show 12</option>
                <option value="15">Show 15</option>
                <option value="30">Show 30</option>
                <option value="all">Show all</option>
            </select>
            <div id="mhDocMeta" class="meta"></div>
        </div>

        <div id="mhDocGrid" class="grid">
            <?php if (empty($docs)): ?>
                <div class="card" style="grid-column: 1 / -1;">
                    <div class="title">No documents found</div>
                    <div class="sub">Upload PDFs into <code>/information/documents</code> to populate this grid.</div>
                </div>
            <?php else: foreach ($docs as $d): ?>
                <?php
                    $label = (string)($d['label'] ?? '');
                    $url = (string)($d['url'] ?? '');
                    $previewUrl = $url . '#page=1&view=FitH&toolbar=0&navpanes=0&scrollbar=0';
                    $file = (string)($d['file'] ?? '');
                    $mtime = (int)($d['mtime'] ?? 0);
                    $size = (int)($d['size'] ?? 0);
                    $ext = strtoupper((string)($d['ext'] ?? ''));
                    $sizeMb = $size > 0 ? round($size / 1024 / 1024, 1) : 0;
                    $when = $mtime > 0 ? date('Y-m-d', $mtime) : '';
                ?>
                <div class="card mh-doc-card" data-name="<?php echo htmlspecialchars(strtolower($label), ENT_QUOTES); ?>" data-url="<?php echo htmlspecialchars($url, ENT_QUOTES); ?>" data-title="<?php echo htmlspecialchars($label, ENT_QUOTES); ?>">
                    <div class="thumb">
                        <iframe class="thumb-pdf" loading="lazy" src="<?php echo htmlspecialchars($previewUrl, ENT_QUOTES); ?>" title="<?php echo htmlspecialchars($label . ' preview', ENT_QUOTES); ?>"></iframe>
                        <div class="shade"></div>
                        <div class="ext"><?php echo htmlspecialchars($ext, ENT_QUOTES); ?></div>
                    </div>
                    <div class="title"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></div>
                    <div class="sub">
                        <span><?php echo $when !== '' ? htmlspecialchars($when, ENT_QUOTES) : '—'; ?></span>
                        <span><?php echo $sizeMb > 0 ? htmlspecialchars((string)$sizeMb, ENT_QUOTES) . ' MB' : '—'; ?></span>
                    </div>
                    <div class="btnrow">
                        <button type="button" class="btn mh-doc-open">View</button>
                        <a class="btn secondary" href="<?php echo htmlspecialchars($url, ENT_QUOTES); ?>" download>Download</a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script src="/gear/viewers/pdf-modal.js?v=<?php echo (int)$pdfViewerV; ?>"></script>
<script>
(function() {
    const search = document.getElementById('mhDocSearch');
    const limitSel = document.getElementById('mhDocLimit');
    const meta = document.getElementById('mhDocMeta');
    const cards = Array.from(document.querySelectorAll('.mh-doc-card'));

    function render() {
        const q = (search && search.value ? search.value : '').trim().toLowerCase();
        const limitRaw = limitSel && limitSel.value ? String(limitSel.value) : '12';
        const limit = (limitRaw === 'all') ? -1 : (parseInt(limitRaw, 10) || 12);

        let matches = [];
        for (const c of cards) {
            const name = String(c.getAttribute('data-name') || '');
            const ok = !q || name.includes(q);
            c.style.display = ok ? '' : 'none';
            if (ok) matches.push(c);
        }

        if (limit > 0) {
            for (let i = 0; i < matches.length; i++) {
                matches[i].style.display = (i < limit) ? '' : 'none';
            }
        }

        if (meta) {
            const shown = (limit > 0) ? Math.min(matches.length, limit) : matches.length;
            meta.textContent = shown + ' shown · ' + matches.length + ' match' + (matches.length === 1 ? '' : 'es');
        }

        if (window.mhDocQueueThumbs) {
            try { window.mhDocQueueThumbs(); } catch (e) {}
        }
    }

    if (search) search.addEventListener('input', render);
    if (limitSel) limitSel.addEventListener('change', render);
    render();

    document.addEventListener('click', function(e) {
        if (e.target && e.target.closest && e.target.closest('a')) return;
        const btn = e.target && e.target.closest ? e.target.closest('.mh-doc-open') : null;
        const card = btn ? btn.closest('.mh-doc-card') : (e.target && e.target.closest ? e.target.closest('.mh-doc-card') : null);
        if (!card) return;
        const url = String(card.getAttribute('data-url') || '');
        const title = String(card.getAttribute('data-title') || 'Document');
        if (window.MHViewers && typeof window.MHViewers.openPdf === 'function') window.MHViewers.openPdf(url, { title: title });
        else window.open(url, '_blank', 'noopener');
    });
})();
</script>
</body>
</html>
