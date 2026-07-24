<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

function findOutputRoot(): ?string
{
    $candidates = [
        '/home/onemeta/public_html/gear/generators/video/output',
    ];
    foreach ($candidates as $p) {
        if (is_dir($p)) {
            return $p;
        }
    }
    return null;
}

function listRecentVideos(string $outputRoot, int $limit): array
{
    $items = @scandir($outputRoot);
    if (!is_array($items)) {
        return [];
    }
    $jobs = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $name)) {
            continue;
        }
        $dir = $outputRoot . '/' . $name;
        if (!is_dir($dir)) {
            continue;
        }
        $mp4 = $dir . '/video.mp4';
        $mtime = @filemtime($dir);
        $jobs[] = [
            'job_id' => $name,
            'has_mp4' => is_file($mp4) && filesize($mp4) > 0,
            'mtime' => is_int($mtime) ? $mtime : 0,
        ];
    }
    usort($jobs, function ($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
    return array_slice($jobs, 0, $limit);
}

$outputRoot = findOutputRoot();
$recent = $outputRoot ? listRecentVideos($outputRoot, 50) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tools: Video Editor</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
    <style>
        body.tools-vimax main.main-content { padding: 40px 0; }
        body.tools-vimax .tools-container { max-width: 980px; margin: 0 auto; padding: 0 24px; box-sizing: border-box; }
        body.tools-vimax .card {
            background: rgba(255,255,255,0.05);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: var(--shadow-card, 0 0 20px rgba(0, 212, 255, 0.1));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        body.tools-vimax label { display: block; margin: 14px 0 6px; opacity: 0.9; }
        body.tools-vimax textarea, body.tools-vimax input, body.tools-vimax select {
            width: 100%;
            box-sizing: border-box;
            background: rgba(0,0,0,0.25);
            border: 1px solid rgba(255,255,255,0.16);
            color: #fff;
            padding: 10px 12px;
            border-radius: 10px;
            font-family: inherit;
        }
        body.tools-vimax textarea { min-height: 110px; resize: vertical; }
        body.tools-vimax .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        body.tools-vimax button {
            margin-top: 14px;
            background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%);
            border: none;
            padding: 12px 16px;
            color: white;
            font-weight: 700;
            border-radius: 10px;
            cursor: pointer;
        }
        body.tools-vimax .bar { height: 10px; background: rgba(255,255,255,0.10); border-radius: 10px; overflow: hidden; }
        body.tools-vimax .bar > div { height: 100%; background: linear-gradient(90deg, #00d4ff, #7c3aed); width: 0%; }
        body.tools-vimax .muted { opacity: 0.85; }
        body.tools-vimax .mono { font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; }
        body.tools-vimax .list { max-height: 280px; overflow: auto; border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 10px; }
        body.tools-vimax .item { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 8px 6px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        body.tools-vimax .item:last-child { border-bottom: 0; }
        body.tools-vimax .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; background: rgba(255,255,255,0.10); font-size: 12px; }
        body.tools-vimax video { width: 100%; margin-top: 12px; display: none; }
        body.tools-vimax a { color: var(--theme-primary, #00d4ff); text-decoration: none; }
        body.tools-vimax .del-btn {
            background: rgba(255,80,80,0.16);
            border: 1px solid rgba(255,80,80,0.35);
            color: #fff;
            padding: 6px 10px;
            border-radius: 10px;
            font-weight: 700;
            margin-top: 0;
        }
        body.tools-vimax .del-btn:hover { border-color: rgba(255,80,80,0.7); }
    </style>
</head>
<body class="tools-vimax">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="tools-container">
        <h1 style="margin-bottom: 10px;">Video Editor</h1>
        <p class="muted" style="margin-bottom: 18px;">Generates a video and shows progress. Download appears only when finished.</p>

        <div class="card">
            <label for="prompt">Prompt / Script / Novel excerpt</label>
            <textarea id="prompt" placeholder="Describe the scene, characters, camera, style, and duration..."></textarea>

            <div class="row">
                <div>
                    <label for="mode">Mode</label>
                    <select id="mode">
                        <option value="idea2video">idea2video</option>
                        <option value="novel2video">novel2video</option>
                        <option value="script2video">script2video</option>
                        <option value="autocameo">autocameo</option>
                    </select>
                </div>
                <div>
                    <label for="duration">Duration (seconds)</label>
                    <input id="duration" type="number" min="2" max="60" value="20" />
                </div>
            </div>

            <label for="seed">Seed (optional)</label>
            <input id="seed" type="number" placeholder="e.g. 1234" />

            <label for="photo">Photo (AutoCameo only)</label>
            <input id="photo" type="file" accept="image/*" />

            <button id="btn">Generate</button>

            <div style="margin-top: 14px;">
                <div class="muted">Job: <span class="mono" id="job">—</span></div>
                <div class="muted" style="margin-top: 6px;">Status: <span id="status">—</span></div>
                <div class="muted" style="margin-top: 6px;" id="stage"></div>
                <div class="bar" style="margin-top: 10px;"><div id="bar"></div></div>
                <div id="links" style="margin-top: 12px;"></div>
                <video id="player" controls></video>
            </div>
        </div>

        <div class="card" style="margin-top: 14px;">
            <div class="row" style="grid-template-columns: 1fr; gap: 6px;">
                <div style="font-weight: 700;">Previous videos</div>
                <div class="muted"><?php echo htmlspecialchars($outputRoot ? $outputRoot : '(output path not found)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
            </div>
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;">
                <label style="margin:0;display:flex;gap:8px;align-items:center;">
                    <input type="checkbox" id="selectAllJobs" />
                    <span class="muted">Select all</span>
                </label>
                <button type="button" id="deleteSelected" class="del-btn" style="padding:8px 12px;">Delete selected</button>
                <span class="muted" id="selectedCount"></span>
            </div>
            <div class="list" style="margin-top: 10px;">
                <?php if (!$outputRoot): ?>
                    <div class="muted">No output directory is available on this host.</div>
                <?php elseif (count($recent) === 0): ?>
                    <div class="muted">No previous jobs found yet.</div>
                <?php else: ?>
                    <?php foreach ($recent as $j): ?>
                        <?php
                        $jobId = (string)$j['job_id'];
                        $public = 'https://metahumans.one/gear/generators/video/output/' . rawurlencode($jobId) . '/video.mp4';
                        $fallback = '/gear/generators/video/generate.php?job_id=' . rawurlencode($jobId) . '&download=1';
                        $poll = '/gear/generators/video/generate.php?job_id=' . rawurlencode($jobId);
                        $badge = $j['has_mp4'] ? 'ready' : 'pending';
                        ?>
                        <div class="item">
                            <input type="checkbox" class="job-check" data-job-id="<?php echo htmlspecialchars($jobId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" />
                            <span class="mono"><?php echo htmlspecialchars($jobId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            <span class="pill"><?php echo htmlspecialchars($badge, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                            <a href="<?php echo htmlspecialchars($poll, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">status</a>
                            <a href="<?php echo htmlspecialchars($public, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" download>download</a>
                            <a href="<?php echo htmlspecialchars($fallback, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" download>download (fallback)</a>
                            <button type="button" class="del-btn" data-job-id="<?php echo htmlspecialchars($jobId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">delete</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<script src="/templates/widgets/notices/popup-notice.js"></script>
<script>
    function mhNotice(message, type = 'info', options = {}) {
        try {
            if (!window._mhPopupNotice && typeof window.PopupNotice !== 'undefined') {
                window._mhPopupNotice = new PopupNotice({ position: 'bottom-left', theme: 'minimal' });
            }
            if (window._mhPopupNotice) {
                return window._mhPopupNotice.show(message, type, options);
            }
        } catch (_) {}
        return null;
    }

    const btn = document.getElementById('btn');
    const outStatus = document.getElementById('status');
    const outStage = document.getElementById('stage');
    const outJob = document.getElementById('job');
    const bar = document.getElementById('bar');
    const links = document.getElementById('links');
    const player = document.getElementById('player');
    const selectAllJobs = document.getElementById('selectAllJobs');
    const deleteSelected = document.getElementById('deleteSelected');
    const selectedCount = document.getElementById('selectedCount');

    function alternateVideoUrl(url) {
        if (!url) return null;
        try {
            const u = new URL(url, window.location.href);
            if (u.hostname === 'metahumans.one') {
                u.hostname = 'usa.metahumans.one';
                return u.toString();
            }
            if (u.hostname === 'usa.metahumans.one') {
                u.hostname = 'metahumans.one';
                return u.toString();
            }
        } catch (_) {}
        return null;
    }

    function setProgress(p) {
        const pct = Math.max(0, Math.min(100, Math.round((p ?? 0) * 100)));
        bar.style.width = pct + '%';
        return pct;
    }

    async function readFileAsDataUrl(file) {
        return await new Promise((resolve, reject) => {
            const r = new FileReader();
            r.onerror = () => reject(new Error('Failed to read file'));
            r.onload = () => resolve(String(r.result || ''));
            r.readAsDataURL(file);
        });
    }

    let polling = null;

    function sanitizeVideoUrl(url) {
        if (!url || typeof url !== 'string') return '';
        return url.trim().replace(/`/g, '');
    }

    function getJobCheckboxes() {
        return Array.from(document.querySelectorAll('.job-check'));
    }

    function updateSelectedCount() {
        const checks = getJobCheckboxes();
        const checked = checks.filter(c => c.checked).length;
        if (selectedCount) {
            selectedCount.textContent = checked > 0 ? (`${checked} selected`) : '';
        }
    }

    async function deleteOneJob(jobId) {
        const resp = await fetch(`/gear/generators/video/generate.php?job_id=${encodeURIComponent(jobId)}&delete=1&format=json`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json().catch(() => null);
        if (!resp.ok || !data || !data.ok) {
            const msg = data && (data.error || data.message) ? String(data.error || data.message) : `Delete failed (${resp.status})`;
            throw new Error(msg);
        }
        return true;
    }

    async function poll(jobId) {
        if (polling) clearTimeout(polling);
        const r = await fetch(`/gear/generators/video/generate.php?job_id=${encodeURIComponent(jobId)}&format=json`, { cache: 'no-store', headers: { 'Accept': 'application/json' } });
        const d = await r.json();

        outJob.textContent = jobId;
        outStatus.textContent = d.status || 'unknown';
        const pct = setProgress(d.progress);
        outStage.textContent = (d.stage ? (d.stage + ' · ') : '') + pct + '%';

        links.innerHTML = '';
        if (d.status === 'completed') {
            const proxy = `/gear/generators/video/generate.php?job_id=${encodeURIComponent(jobId)}&download=1`;
            const cleaned = sanitizeVideoUrl(d.video_url);
            if (cleaned) {
                links.innerHTML = `<a href="${cleaned}" download>Download video</a> · <a href="${proxy}" download>Download (fallback)</a>`;
                try {
                    const head = await fetch(cleaned, { method: 'HEAD', cache: 'no-store' });
                    if (head.ok) {
                        player.src = cleaned;
                    } else {
                        const alt = alternateVideoUrl(cleaned);
                        if (alt) {
                            try {
                                const headAlt = await fetch(alt, { method: 'HEAD', cache: 'no-store' });
                                player.src = headAlt.ok ? alt : proxy;
                            } catch (_) {
                                player.src = proxy;
                            }
                        } else {
                            player.src = proxy;
                        }
                    }
                } catch (_) {
                    const alt = alternateVideoUrl(cleaned);
                    if (alt) {
                        try {
                            const headAlt = await fetch(alt, { method: 'HEAD', cache: 'no-store' });
                            player.src = headAlt.ok ? alt : proxy;
                        } catch (_) {
                            player.src = proxy;
                        }
                    } else {
                        player.src = proxy;
                    }
                }
            } else {
                links.innerHTML = `<a href="${proxy}" download>Download (fallback)</a>`;
                player.src = proxy;
            }
            player.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Generate';
            return;
        }

        if (d.status === 'failed') {
            mhNotice(d.error ? ('Failed: ' + d.error) : 'Failed.', 'error');
            btn.disabled = false;
            btn.textContent = 'Generate';
            return;
        }

        player.style.display = 'none';
        polling = setTimeout(() => poll(jobId), 1200);
    }

    btn.addEventListener('click', async () => {
        const prompt = (document.getElementById('prompt').value || '').trim();
        const mode = document.getElementById('mode').value;
        const duration = Number(document.getElementById('duration').value || 0);
        const seedRaw = (document.getElementById('seed').value || '').trim();
        const photo = document.getElementById('photo').files && document.getElementById('photo').files[0] ? document.getElementById('photo').files[0] : null;

        if (!prompt) {
            mhNotice('Prompt is required', 'error');
            return;
        }

        if (mode === 'autocameo' && !photo) {
            mhNotice('AutoCameo needs a photo', 'error');
            return;
        }

        if (polling) clearTimeout(polling);
        links.innerHTML = '';
        player.style.display = 'none';
        outJob.textContent = '—';
        outStatus.textContent = 'Submitting…';
        outStage.textContent = '';
        setProgress(0);

        btn.disabled = true;
        btn.textContent = 'Generating…';

        let initImageB64 = null;
        if (mode === 'autocameo' && photo) {
            initImageB64 = await readFileAsDataUrl(photo);
        }

        const body = {
            prompt,
            mode,
            duration_seconds: Number.isFinite(duration) && duration > 0 ? duration : 20,
            seed: seedRaw !== '' ? Number(seedRaw) : null,
            init_image_b64: initImageB64
        };

        try {
            const resp = await fetch('/gear/generators/video/generate.php?format=json', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(body)
            });
            const data = await resp.json();
            if (!resp.ok || !data.job_id) {
                let msg = '';
                if (data && typeof data === 'object') {
                    if (data.error === 'upstream_missing_job_id' && data.message) {
                        msg = String(data.message);
                    } else if (data.error === 'upstream_rejected' && data.message) {
                        msg = String(data.message);
                    } else if (data.message) {
                        msg = String(data.message);
                    } else if (data.error) {
                        msg = String(data.error);
                    }
                    if (!msg && data.upstream) {
                        try { msg = JSON.stringify(data.upstream); } catch (_) {}
                    }
                }
                mhNotice(msg ? msg : ('Request failed: ' + resp.status), 'error');
                btn.disabled = false;
                btn.textContent = 'Generate';
                return;
            }
            outStatus.textContent = 'Queued';
            poll(data.job_id);
        } catch (e) {
            mhNotice('Failed: ' + (e && e.message ? e.message : String(e)), 'error');
            btn.disabled = false;
            btn.textContent = 'Generate';
        }
    });

    if (selectAllJobs) {
        selectAllJobs.addEventListener('change', () => {
            const checks = getJobCheckboxes();
            for (const c of checks) {
                c.checked = !!selectAllJobs.checked;
            }
            updateSelectedCount();
        });
    }

    document.addEventListener('change', (e) => {
        const t = e.target;
        if (t && t.classList && t.classList.contains('job-check')) {
            const checks = getJobCheckboxes();
            const allChecked = checks.length > 0 && checks.every(c => c.checked);
            const anyUnchecked = checks.some(c => !c.checked);
            if (selectAllJobs) {
                selectAllJobs.checked = allChecked;
                selectAllJobs.indeterminate = !allChecked && !anyUnchecked;
            }
            updateSelectedCount();
        }
    });

    if (deleteSelected) {
        deleteSelected.addEventListener('click', async () => {
            const checks = getJobCheckboxes().filter(c => c.checked);
            if (checks.length === 0) {
                mhNotice('No videos selected', 'info');
                return;
            }
            if (!confirm(`Delete ${checks.length} selected video job(s)?`)) {
                return;
            }
            deleteSelected.disabled = true;
            if (selectAllJobs) selectAllJobs.disabled = true;
            for (const c of checks) {
                c.disabled = true;
            }
            let ok = 0;
            let failed = 0;
            for (const c of checks) {
                const jobId = c.getAttribute('data-job-id') || '';
                if (!jobId) continue;
                try {
                    await deleteOneJob(jobId);
                    ok++;
                    const row = c.closest('.item');
                    if (row) row.remove();
                } catch (err) {
                    failed++;
                    mhNotice(`Delete failed for ${jobId}: ${err && err.message ? err.message : String(err)}`, 'error');
                    c.disabled = false;
                }
            }
            if (selectAllJobs) {
                selectAllJobs.disabled = false;
                selectAllJobs.checked = false;
                selectAllJobs.indeterminate = false;
            }
            deleteSelected.disabled = false;
            updateSelectedCount();
            mhNotice(`Deleted ${ok} job(s)` + (failed ? `, ${failed} failed` : ''), failed ? 'error' : 'success');
        });
    }

    document.addEventListener('click', async (e) => {
        const t = e.target;
        if (!t || !t.classList || !t.classList.contains('del-btn')) {
            return;
        }
        const jobId = t.getAttribute('data-job-id') || '';
        if (!jobId) {
            return;
        }
        if (!confirm('Delete this video and its job folder?')) {
            return;
        }
        t.disabled = true;
        try {
            await deleteOneJob(jobId);
            const row = t.closest('.item');
            if (row) {
                row.remove();
            }
            mhNotice('Deleted', 'success');
        } catch (err) {
            mhNotice(err && err.message ? err.message : 'Delete failed', 'error');
            t.disabled = false;
        }
    });
</script>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
