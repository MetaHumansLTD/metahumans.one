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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tools</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.tools-index main.main-content { padding: 40px 0; }
        body.tools-index .wrap { max-width: 1100px; margin: 0 auto; padding: 0 24px; box-sizing: border-box; }
        body.tools-index a { color: inherit; text-decoration: none; }
        body.tools-index .hero { display:flex; align-items:flex-end; justify-content:space-between; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        body.tools-index .hero h1 { margin: 0; font-family: 'Orbitron', sans-serif; letter-spacing: 1px; }
        body.tools-index .hero .sub { color: rgba(255,255,255,0.72); max-width: 720px; line-height: 1.45; }
        body.tools-index .grid { display:grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        body.tools-index .tool-card {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,0.12);
            background: radial-gradient(900px 240px at 18% 8%, color-mix(in srgb, var(--accent, #00d4ff) 26%, transparent), rgba(0,0,0,0)) , rgba(255,255,255,0.04);
            box-shadow: 0 18px 60px rgba(0,0,0,0.45);
            padding: 16px;
            min-height: 190px;
            display:flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 12px;
            transition: transform 180ms ease, border-color 180ms ease, opacity 180ms ease;
            backdrop-filter: blur(10px);
            transform-style: preserve-3d;
            will-change: transform;
            --rx: 0deg;
            --ry: 0deg;
            --gx: 50%;
            --gy: 50%;
        }
        body.tools-index .tool-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse at 20% 20%, color-mix(in srgb, var(--accent, #00d4ff) 22%, transparent), transparent 65%);
            pointer-events: none;
        }
        body.tools-index .tool-card::after {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 260px 200px at var(--gx) var(--gy), color-mix(in srgb, var(--accent, #00d4ff) 28%, transparent), transparent 65%);
            opacity: 0;
            transition: opacity 220ms ease;
            pointer-events: none;
        }
        body.tools-index .tool-card .shimmer {
            pointer-events: none;
            position: absolute;
            inset: 0;
            transform: translateX(-60%) skewX(-12deg);
            width: 55%;
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.05), transparent);
            opacity: 0.8;
            transition: transform 700ms ease;
        }
        body.tools-index .tool-card:hover { border-color: color-mix(in srgb, var(--accent, #00d4ff) 70%, rgba(255,255,255,0.18)); }
        body.tools-index .tool-card.is-hover { transform: perspective(900px) rotateX(var(--rx)) rotateY(var(--ry)) translateY(-3px); }
        body.tools-index .tool-card.is-hover::after { opacity: 1; }
        body.tools-index .tool-card.is-hover .shimmer { transform: translateX(280%) skewX(-12deg); }
        body.tools-index .tool-card.is-dim { opacity: 0.55; transform: scale(0.98); }
        body.tools-index .tool-top { display:flex; align-items:flex-start; justify-content:space-between; gap: 12px; }
        body.tools-index .tool-ico {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display:flex;
            align-items:center;
            justify-content:center;
            background: color-mix(in srgb, var(--accent, #00d4ff) 16%, rgba(0,0,0,0.20));
            border: 1px solid color-mix(in srgb, var(--accent, #00d4ff) 45%, rgba(255,255,255,0.14));
            color: color-mix(in srgb, var(--accent, #00d4ff) 90%, #fff);
            flex: 0 0 auto;
            font-weight: 900;
        }
        body.tools-index .tool-name { font-weight: 900; letter-spacing: 0.5px; font-family: 'Orbitron', sans-serif; }
        body.tools-index .tool-desc { color: rgba(255,255,255,0.72); line-height: 1.45; margin-top: 6px; }
        body.tools-index .tool-cta { display:flex; align-items:center; justify-content:space-between; gap: 10px; }
        body.tools-index .pill {
            display:inline-flex;
            align-items:center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid color-mix(in srgb, var(--accent, #00d4ff) 55%, rgba(255,255,255,0.18));
            color: color-mix(in srgb, var(--accent, #00d4ff) 85%, #fff);
            font-weight: 900;
            letter-spacing: 0.5px;
            background: rgba(0,0,0,0.20);
        }
        @media (max-width: 980px) { body.tools-index .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 620px) { body.tools-index .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="tools-index">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="wrap">
        <div class="hero">
            <div>
                <h1>TOOLS</h1>
                <div class="sub">Utilities for media, voice, and operational dashboards. The infra dashboard is the primary operational entry point.</div>
            </div>
        </div>
        <div class="grid">
            <a class="tool-card" href="/gear/settings/infra.php" style="--accent:#38bdf8;">
                <div class="shimmer" aria-hidden="true"></div>
                <div class="tool-top">
                    <div>
                        <div class="tool-name">Infra Dashboard</div>
                        <div class="tool-desc">Primary ops entry for hosts, roles, IPs, listening ports, health, drift, and exposure.</div>
                    </div>
                    <div class="tool-ico">OPS</div>
                </div>
                <div class="tool-cta">
                    <div class="pill">Primary</div>
                </div>
            </a>

            <a class="tool-card" href="/hub/tools/video-grid.php" style="--accent:#00d4ff;">
                <div class="shimmer" aria-hidden="true"></div>
                <div class="tool-top">
                    <div>
                        <div class="tool-name">Video Grid</div>
                        <div class="tool-desc">Browse and play videos from the platform library.</div>
                    </div>
                    <div class="tool-ico">▶</div>
                </div>
                <div class="tool-cta">
                    <div class="pill">Open</div>
                </div>
            </a>

            <a class="tool-card" href="/hub/tools/document-viewer.php" style="--accent:#a855f7;">
                <div class="shimmer" aria-hidden="true"></div>
                <div class="tool-top">
                    <div>
                        <div class="tool-name">Document Viewer</div>
                        <div class="tool-desc">Search and view platform documents in a modal.</div>
                    </div>
                    <div class="tool-ico">PDF</div>
                </div>
                <div class="tool-cta">
                    <div class="pill">Open</div>
                </div>
            </a>

            <a class="tool-card" href="/hub/tools/vimax.php" style="--accent:#22c55e;">
                <div class="shimmer" aria-hidden="true"></div>
                <div class="tool-top">
                    <div>
                        <div class="tool-name">Video Editor</div>
                        <div class="tool-desc">Create and edit scenes for generated videos.</div>
                    </div>
                    <div class="tool-ico">VID</div>
                </div>
                <div class="tool-cta">
                    <div class="pill">Open</div>
                </div>
            </a>

            <a class="tool-card" href="/hub/tools/voicebox.php" style="--accent:#fb7185;">
                <div class="shimmer" aria-hidden="true"></div>
                <div class="tool-top">
                    <div>
                        <div class="tool-name">Voicebox</div>
                        <div class="tool-desc">Voice and speech tools for agents.</div>
                    </div>
                    <div class="tool-ico">MIC</div>
                </div>
                <div class="tool-cta">
                    <div class="pill">Open</div>
                </div>
            </a>

            <a class="tool-card" href="/hub/tools/hermes.php" style="--accent:#fbbf24;">
                <div class="shimmer" aria-hidden="true"></div>
                <div class="tool-top">
                    <div>
                        <div class="tool-name">Hermes</div>
                        <div class="tool-desc">Service health and embedded UI access.</div>
                    </div>
                    <div class="tool-ico">SYS</div>
                </div>
                <div class="tool-cta">
                    <div class="pill">Open</div>
                </div>
            </a>
        </div>
    </div>
</main>
<script>
(function() {
    const cards = Array.from(document.querySelectorAll('.tool-card'));
    const maxTilt = 9;

    function setDim(active) {
        for (const c of cards) c.classList.toggle('is-dim', active && c !== active);
    }

    function onMove(e) {
        const el = e.currentTarget;
        const rect = el.getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width;
        const y = (e.clientY - rect.top) / rect.height;
        const rx = ((0.5 - y) * (maxTilt * 2)).toFixed(2) + 'deg';
        const ry = (((x - 0.5) * (maxTilt * 2))).toFixed(2) + 'deg';
        el.style.setProperty('--rx', rx);
        el.style.setProperty('--ry', ry);
        el.style.setProperty('--gx', Math.max(0, Math.min(100, x * 100)).toFixed(1) + '%');
        el.style.setProperty('--gy', Math.max(0, Math.min(100, y * 100)).toFixed(1) + '%');
    }

    function onEnter(e) {
        const el = e.currentTarget;
        el.classList.add('is-hover');
        setDim(el);
    }

    function onLeave(e) {
        const el = e.currentTarget;
        el.classList.remove('is-hover');
        el.style.setProperty('--rx', '0deg');
        el.style.setProperty('--ry', '0deg');
        el.style.setProperty('--gx', '50%');
        el.style.setProperty('--gy', '50%');
        setDim(null);
    }

    for (const c of cards) {
        c.addEventListener('pointerenter', onEnter);
        c.addEventListener('pointermove', onMove);
        c.addEventListener('pointerleave', onLeave);
    }
})();
</script>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
