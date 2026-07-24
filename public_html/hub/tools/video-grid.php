<?php
if (isset($_GET['thumb'])) {
    @ini_set('display_errors', '0');
    @error_reporting(0);

    $baseDir = dirname(__DIR__, 2);
    $videoDir = $baseDir . '/information/videos';

    $f = trim((string)$_GET['thumb']);
    $f = basename($f);
    $svg = function () {
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="520" height="292" viewBox="0 0 520 292"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#00d4ff" stop-opacity=".18"/><stop offset="1" stop-color="#000" stop-opacity="0"/></linearGradient></defs><rect width="520" height="292" rx="14" fill="url(#g)"/><rect x="1" y="1" width="518" height="290" rx="13" fill="none" stroke="rgba(255,255,255,.14)"/><circle cx="260" cy="146" r="28" fill="rgba(0,212,255,.85)"/><path d="M253 134 L277 146 L253 158 Z" fill="#001018"/></svg>';
        exit;
    };

    if ($f === '' || $f[0] === '.') {
        $svg();
    }
    $full = $videoDir . '/' . $f;
    if (!is_file($full)) {
        $svg();
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'm4v', 'mov', 'webm'], true)) {
        $svg();
    }
    $mtime = @filemtime($full) ?: 0;
    $key = sha1($full . '|' . $mtime);
    $out = rtrim((string)sys_get_temp_dir(), '/') . '/mh_vthumb_' . $key . '.jpg';

    if (!is_file($out) || @filesize($out) < 1024) {
        $ffmpeg = '/usr/bin/ffmpeg';
        if (!is_file($ffmpeg)) {
            $svg();
        }
        @unlink($out);
        $cmd = escapeshellcmd($ffmpeg) .
            ' -hide_banner -loglevel error' .
            ' -ss 2' .
            ' -i ' . escapeshellarg($full) .
            ' -frames:v 1 -an' .
            ' -vf ' . escapeshellarg('scale=520:-1') .
            ' -q:v 6' .
            ' -y ' . escapeshellarg($out);
        @exec($cmd, $o, $rc);
        if ($rc !== 0 || !is_file($out) || @filesize($out) < 1024) {
            $svg();
        }
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($out);
    exit;
}

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/tools/video-grid.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/tools/video-grid.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$baseDir = dirname(__DIR__, 2);
$videoDir = $baseDir . '/information/videos';
$videos = [];

if (is_dir($videoDir)) {
    $items = @scandir($videoDir);
    if (is_array($items)) {
        foreach ($items as $f) {
            if (!is_string($f) || $f === '' || $f[0] === '.') continue;
            $full = $videoDir . '/' . $f;
            if (!is_file($full)) continue;
            $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
            if (!in_array($ext, ['mp4', 'm4v', 'mov', 'webm'], true)) continue;
            $pageUrl = '/information/videos/?' . http_build_query(['f' => $f]);
            $rawUrl = '/information/videos/?' . http_build_query(['f' => $f, 'raw' => '1']);
            $label = preg_replace('/\\.[^.]+$/', '', $f);
            $label = str_replace(['_', '-'], ' ', (string)$label);
            $label = trim(preg_replace('/\\s+/', ' ', (string)$label));
            $videos[] = [
                'file' => $f,
                'label' => $label !== '' ? $label : $f,
                'url' => $rawUrl,
                'page_url' => $pageUrl,
                'mtime' => @filemtime($full) ?: 0,
                'size' => @filesize($full) ?: 0,
            ];
        }
    }
}

usort($videos, function ($a, $b) {
    $am = (int)($a['mtime'] ?? 0);
    $bm = (int)($b['mtime'] ?? 0);
    if ($am !== $bm) return $bm <=> $am;
    return strcmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
});

$playerPath = $baseDir . '/gear/players/mp4-modal.js';
$playerV = is_file($playerPath) ? (int)filemtime($playerPath) : 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Video Grid</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.video-grid main.main-content { padding: 40px 0; }
        body.video-grid .wrap { max-width: 1100px; margin: 0 auto; padding: 0 24px; box-sizing: border-box; }
        body.video-grid h1 { margin: 0 0 8px 0; font-family: 'Orbitron', sans-serif; letter-spacing: 1px; }
        body.video-grid .hint { color: rgba(255,255,255,0.7); margin-bottom: 16px; }
        body.video-grid .controls { display:flex; gap: 12px; flex-wrap: wrap; align-items:center; margin: 14px 0 18px; }
        body.video-grid .controls input[type="search"] { width: 320px; max-width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color: #fff; }
        body.video-grid .controls select { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color: #fff; }
        body.video-grid .controls .meta { margin-left: auto; color: rgba(255,255,255,0.65); font-size: 0.95rem; }
        body.video-grid .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
        body.video-grid .card { background: rgba(0, 212, 255, 0.05); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 14px; min-width: 0; cursor: pointer; }
        body.video-grid .thumb { height: 180px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.10); background: radial-gradient(800px 220px at 30% 10%, rgba(0,212,255,0.12), rgba(0,0,0,0)), rgba(0,0,0,0.25); display:flex; align-items:center; justify-content:center; position: relative; overflow: hidden; }
        body.video-grid .thumb-video { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; background:#000; }
        body.video-grid .thumb .shade { position:absolute; inset:0; background: linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.45)); opacity: 0.8; pointer-events: none; }
        body.video-grid .thumb .play { position:absolute; inset:auto; width: 46px; height: 46px; border-radius: 999px; background: rgba(0,212,255,0.9); color:#001018; display:flex; align-items:center; justify-content:center; font-weight: 900; pointer-events: none; }
        body.video-grid .title { margin: 12px 0 6px 0; font-weight: 900; color: rgba(255,255,255,0.92); overflow-wrap:anywhere; }
        body.video-grid .sub { color: rgba(255,255,255,0.65); font-size: 0.9rem; display:flex; justify-content:space-between; gap: 10px; flex-wrap: wrap; }
        body.video-grid .btnrow { margin-top: 10px; display:flex; gap: 10px; flex-wrap: wrap; }
        body.video-grid .btn { width: auto; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--theme-primary, #00d4ff); background: var(--theme-primary, #00d4ff); color: #001018; font-weight: 900; letter-spacing: 0.5px; cursor:pointer; }
        body.video-grid .btn.secondary { background: transparent; color: var(--theme-primary, #00d4ff); }
        @media (max-width: 520px) { body.video-grid .controls input[type="search"] { width: 100%; } body.video-grid .controls .meta { margin-left: 0; width: 100%; } }
    </style>
</head>
<body class="video-grid">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="wrap">
        <h1>VIDEO GRID</h1>
        <div class="hint">Browse videos in <code>/information/videos</code>. Use search and display count.</div>

        <div class="controls">
            <input id="mhVideoSearch" type="search" placeholder="Search by name..." />
            <select id="mhVideoLimit">
                <option value="3">Show 3</option>
                <option value="9">Show 9</option>
                <option value="12" selected>Show 12</option>
                <option value="15">Show 15</option>
                <option value="30">Show 30</option>
                <option value="all">Show all</option>
            </select>
            <div id="mhVideoMeta" class="meta"></div>
        </div>

        <div id="mhVideoGrid" class="grid">
            <?php if (empty($videos)): ?>
                <div class="card" style="grid-column: 1 / -1; cursor: default;">
                    <div class="title">No videos found</div>
                    <div class="sub">Upload MP4s into <code>/information/videos</code> to populate this grid.</div>
                </div>
            <?php else: foreach ($videos as $v): ?>
                <?php
                    $label = (string)($v['label'] ?? '');
                    $url = (string)($v['url'] ?? '');
                    $pageUrl = (string)($v['page_url'] ?? '');
                    $file = (string)($v['file'] ?? '');
                    $mtime = (int)($v['mtime'] ?? 0);
                    $size = (int)($v['size'] ?? 0);
                    $sizeMb = $size > 0 ? round($size / 1024 / 1024, 1) : 0;
                    $when = $mtime > 0 ? date('Y-m-d', $mtime) : '';
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $mime = 'video/mp4';
                    if ($ext === 'webm') {
                        $mime = 'video/webm';
                    } elseif ($ext === 'mov') {
                        $mime = 'video/quicktime';
                    } elseif ($ext === 'm4v') {
                        $mime = 'video/x-m4v';
                    }
                ?>
                <div class="card mh-video-card" data-name="<?php echo htmlspecialchars(strtolower($label), ENT_QUOTES); ?>" data-url="<?php echo htmlspecialchars($url, ENT_QUOTES); ?>" data-page-url="<?php echo htmlspecialchars($pageUrl, ENT_QUOTES); ?>" data-title="<?php echo htmlspecialchars($label, ENT_QUOTES); ?>">
                    <div class="thumb">
                        <video class="thumb-video" preload="metadata" controls playsinline>
                            <source src="<?php echo htmlspecialchars($url, ENT_QUOTES); ?>" type="<?php echo htmlspecialchars($mime, ENT_QUOTES); ?>">
                        </video>
                        <div class="shade"></div>
                        <div class="play">▶</div>
                    </div>
                    <div class="title"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></div>
                    <div class="sub">
                        <span><?php echo $when !== '' ? htmlspecialchars($when, ENT_QUOTES) : '—'; ?></span>
                        <span><?php echo $sizeMb > 0 ? htmlspecialchars((string)$sizeMb, ENT_QUOTES) . ' MB' : '—'; ?></span>
                    </div>
                    <div class="btnrow">
                        <button type="button" class="btn mh-video-play">Watch</button>
                        <a class="btn secondary" href="<?php echo htmlspecialchars($url, ENT_QUOTES); ?>" download>Download</a>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script src="/gear/players/mp4-modal.js?v=<?php echo (int)$playerV; ?>"></script>
<script>
(function() {
    const search = document.getElementById('mhVideoSearch');
    const limitSel = document.getElementById('mhVideoLimit');
    const meta = document.getElementById('mhVideoMeta');
    const cards = Array.from(document.querySelectorAll('.mh-video-card'));

    function openCard(card) {
        if (!card) return;
        const url = String(card.getAttribute('data-url') || '');
        const title = String(card.getAttribute('data-title') || 'Video');
        if (window.MHPlayers && typeof window.MHPlayers.openMp4 === 'function') {
            window.MHPlayers.openMp4(url, { title });
        } else {
            window.open(url, '_blank', 'noopener');
        }
    }

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
    }

    document.addEventListener('click', function(e) {
        const btn = e.target && e.target.closest ? e.target.closest('.mh-video-play') : null;
        if (btn) {
            const card = btn.closest('.mh-video-card');
            openCard(card);
            return;
        }
        const card = e.target && e.target.closest ? e.target.closest('.mh-video-card') : null;
        if (card && !e.target.closest('a') && !e.target.closest('video')) {
            openCard(card);
        }
    });

    if (search) search.addEventListener('input', render);
    if (limitSel) limitSel.addEventListener('change', render);
    render();

})();
</script>
</body>
</html>
