<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    $redirect = '/hub/workbench/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$mode = isset($_GET['mode']) ? strtolower((string)$_GET['mode']) : 'do';
if (!in_array($mode, ['do', 'build', 'develop'], true)) {
    $mode = 'do';
}

$username = (string)($_SESSION['mh_auth_user'] ?? '');
mh_auth_load_user_context($username);

$persona = (string)($_SESSION['mh_selected_persona'] ?? ($_SESSION['mh_auth_persona'] ?? ''));
if ($persona === '') {
    $persona = 'MH-' . $username;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans Workbench</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.workbench-page main.main-content { background: #0a0a0f; color: #e8e8f0; font-family: Rajdhani, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 28px 16px; }
        .card { background: rgba(10,18,40,0.65); border: 1px solid rgba(0,255,255,0.18); border-radius: 14px; padding: 18px; backdrop-filter: blur(10px); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; }
        .row.split { justify-content: space-between; align-items: center; }
        .pill { padding: 8px 10px; border-radius: 999px; background: rgba(0,255,255,0.08); border: 1px solid rgba(0,255,255,0.16); color: #b9ffff; font-size: 14px; }
        .tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .tab { padding: 8px 10px; border-radius: 999px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.10); color: rgba(232,232,240,0.85); text-decoration: none; font-size: 14px; }
        .tab.active { background: rgba(0,255,255,0.10); border-color: rgba(0,255,255,0.22); color: #b9ffff; }
        textarea { width: 100%; min-height: 160px; resize: vertical; border-radius: 12px; border: 1px solid rgba(0,255,255,0.18); background: rgba(0,0,0,0.45); color: #e8e8f0; padding: 12px; font-size: 16px; outline: none; }
        button { border: 1px solid rgba(0,255,255,0.22); background: rgba(0,255,255,0.08); color: #b9ffff; padding: 10px 14px; border-radius: 10px; cursor: pointer; font-weight: 600; }
        button:disabled { opacity: 0.55; cursor: not-allowed; }
        select { border-radius: 10px; border: 1px solid rgba(255,255,255,0.14); background: rgba(0,0,0,0.35); color: #e8e8f0; padding: 10px 12px; font-weight: 600; }
        pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.35); border: 1px solid rgba(0,255,255,0.12); border-radius: 12px; padding: 12px; margin: 12px 0 0; }
        h1 { font-family: Orbitron, Rajdhani, sans-serif; margin: 0 0 10px; font-size: 22px; }
        .muted { color: rgba(232,232,240,0.75); }
        .grid { display: grid; grid-template-columns: 1fr; gap: 12px; margin-top: 12px; }
        @media (min-width: 980px) { .grid { grid-template-columns: 1.1fr 0.9fr; } }
        .panel { background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.10); border-radius: 12px; padding: 12px; }
        .panel h2 { margin: 0 0 10px; font-size: 14px; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(185,255,255,0.85); }
        .filelist { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }
        .filelist button { font-family: inherit; font-size: 12px; padding: 8px 10px; }
        .inline { display: inline-flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .link { color: #b9ffff; text-decoration: none; }
    </style>
</head>
<body class="workbench-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
<div class="wrap">
    <div class="card">
        <div class="row split">
            <div>
                <h1>Meta Humans Workbench</h1>
                <div class="muted">Persona-driven execution with tenant isolation.</div>
            </div>
            <div class="row" style="align-items:center;">
                <div class="pill"><?php echo htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="pill"><?php echo htmlspecialchars($persona, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="row split" style="margin-top: 14px;">
            <div class="tabs">
                <a class="tab <?php echo $mode === 'do' ? 'active' : ''; ?>" href="/hub/workbench/?mode=do">Do</a>
                <a class="tab <?php echo $mode === 'build' ? 'active' : ''; ?>" href="/hub/workbench/?mode=build">Build</a>
                <a class="tab <?php echo $mode === 'develop' ? 'active' : ''; ?>" href="/hub/workbench/?mode=develop">Develop</a>
            </div>
            <div class="inline">
                <select id="personaSelect"></select>
                <button id="btnRefreshContext">Refresh</button>
                <a class="link" href="/hub/workbench/status.php">Status</a>
            </div>
        </div>

        <div class="grid">
            <div class="panel">
                <h2>Instructions</h2>
                <textarea id="prompt" placeholder="Describe what you want the Persona to build or change..."></textarea>
                <div class="row" style="margin-top: 12px;">
                    <button id="btnPlan">Generate Plan</button>
                    <button id="btnRun" style="<?php echo $mode === 'develop' ? 'display:none' : ''; ?>">Execute (Safe)</button>
                    <button id="btnUploadAudio">Voice</button>
                    <button id="btnUploadImage">Vision</button>
                    <input id="audioFile" type="file" accept="audio/*" style="display:none" />
                    <input id="imageFile" type="file" accept="image/*" style="display:none" />
                </div>
                <pre id="out"></pre>
            </div>
            <div class="panel">
                <h2><?php echo htmlspecialchars(strtoupper($mode), ENT_QUOTES, 'UTF-8'); ?> Mode</h2>
                <div id="modePanel" class="muted"></div>
                <div id="buildPanel" style="<?php echo $mode === 'build' ? '' : 'display:none'; ?>; margin-top: 12px;">
                    <div class="row filelist">
                        <button id="btnListFiles">List Files</button>
                        <button id="btnReadFile">Read Selected</button>
                    </div>
                    <pre id="filesOut" class="filelist"></pre>
                </div>
                <div id="developPanel" style="<?php echo $mode === 'develop' ? '' : 'display:none'; ?>; margin-top: 12px;">
                    <div class="muted">Meta Humans developer workspace + identity propagation.</div>

                    <div class="panel" style="margin-top: 12px;">
                        <h2>Meta Humans Workspace</h2>
                        <div class="muted" style="font-size: 13px; line-height: 1.5;">
                            <div><span class="pill" style="padding: 4px 8px;">build</span> v0.0.7</div>
                            <div><span class="pill" style="padding: 4px 8px;">context</span> auth_source: <span class="mono" id="devAuthSource"></span></div>
                            <div style="margin-top: 8px;">
                                The workspace app uses a backend proxy endpoint:
                                <span class="mono">GET /meta-humans/context</span>,
                                which forwards your identity token to:
                                <span class="mono">/hub/workbench/api/context.php</span>
                            </div>
                        </div>
                        <div class="row" style="margin-top: 10px;">
                            <a class="tab" href="/hub/workbench/panels/persona-panel-preview.php">Persona Panel Preview</a>
                            <button id="btnLaunchWorkspace" type="button">Launch Meta Humans Workspace</button>
                        </div>
                        <div class="muted" style="margin-top: 10px; font-size: 13px; line-height: 1.5;">
                            To use it:
                            <div class="mono" style="margin-top: 6px;">
                                1) Launch workspace<br/>
                                2) Open the “Persona” panel to confirm context is present
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="margin-top: 12px;">
                        <h2>Workspace API</h2>
                        <div class="muted" style="font-size: 13px; line-height: 1.5;">
                            <div><span class="pill" style="padding: 4px 8px;">endpoint</span> <span class="mono">POST /hub/workbench/api/agent/chat/</span></div>
                            <div><span class="pill" style="padding: 4px 8px;">trace</span> <span class="mono">GET /hub/workbench/api/trace/&lt;trace_id&gt;</span></div>
                        </div>
                        <div style="margin-top: 10px;">
                            <textarea id="devMessage" placeholder="Send a message to the Workspace Chat API..."></textarea>
                            <div class="row" style="margin-top: 10px;">
                                <button id="btnWorkspaceChat" type="button">Send</button>
                            </div>
                            <pre id="devChatOut"></pre>
                        </div>
                    </div>

                    <div class="panel" style="margin-top: 12px;">
                        <h2>Job Runner (mh-agent-api)</h2>
                        <div class="muted" style="font-size: 13px; line-height: 1.5;">
                            <div><span class="pill" style="padding: 4px 8px;">create</span> <span class="mono">POST /hub/workbench/api/agent/jobs/</span></div>
                            <div><span class="pill" style="padding: 4px 8px;">events</span> <span class="mono">GET /hub/workbench/api/agent/jobs/events.php?job_id=&lt;id&gt;</span></div>
                        </div>
                        <div style="margin-top: 10px;">
                            <textarea id="devJobGoal" placeholder="Describe a task to run as a headless job..."></textarea>
                            <div class="row" style="margin-top: 10px;">
                                <button id="btnCreateJob" type="button">Create Job</button>
                            </div>
                            <pre id="devJobOut"></pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<script>
    const out = document.getElementById('out');
    const promptEl = document.getElementById('prompt');
    const btnPlan = document.getElementById('btnPlan');
    const btnRun = document.getElementById('btnRun');
    const btnRefreshContext = document.getElementById('btnRefreshContext');
    const personaSelect = document.getElementById('personaSelect');
    const btnUploadAudio = document.getElementById('btnUploadAudio');
    const btnUploadImage = document.getElementById('btnUploadImage');
    const audioFile = document.getElementById('audioFile');
    const imageFile = document.getElementById('imageFile');
    const btnListFiles = document.getElementById('btnListFiles');
    const btnReadFile = document.getElementById('btnReadFile');
    const filesOut = document.getElementById('filesOut');
    const btnLaunchWorkspace = document.getElementById('btnLaunchWorkspace');
    const btnWorkspaceChat = document.getElementById('btnWorkspaceChat');
    const devMessage = document.getElementById('devMessage');
    const devChatOut = document.getElementById('devChatOut');
    const btnCreateJob = document.getElementById('btnCreateJob');
    const devJobGoal = document.getElementById('devJobGoal');
    const devJobOut = document.getElementById('devJobOut');

    let currentContext = null;
    let selectedFile = null;

    function setBusy(b) {
        btnPlan.disabled = b;
        if (btnRun) btnRun.disabled = b;
        btnRefreshContext.disabled = b;
        personaSelect.disabled = b;
        btnUploadAudio.disabled = b;
        btnUploadImage.disabled = b;
        if (btnListFiles) btnListFiles.disabled = b;
        if (btnReadFile) btnReadFile.disabled = b;
    }

    async function json(url, payload) {
        const res = await fetch(url, {
            method: payload ? 'POST' : 'GET',
            headers: payload ? { 'Content-Type': 'application/json' } : undefined,
            body: payload ? JSON.stringify(payload) : undefined
        });
        const text = await res.text();
        try { return JSON.parse(text); } catch { return { success: false, error: 'invalid_json', raw: text, status: res.status }; }
    }

    async function refreshContext() {
        const ctx = await json('/hub/workbench/api/context.php');
        currentContext = ctx && ctx.success ? ctx : null;
        const authEl = document.getElementById('devAuthSource');
        if (authEl) authEl.textContent = (ctx && ctx.success && ctx.context && ctx.context.auth_source) ? ctx.context.auth_source : '';
        return ctx;
    }

    if (btnLaunchWorkspace) {
        btnLaunchWorkspace.addEventListener('click', () => {
            window.open('/hub/workbench/workspace.php', '_blank', 'noopener');
        });
    }

    if (btnWorkspaceChat && devMessage && devChatOut) {
        btnWorkspaceChat.addEventListener('click', async () => {
            setBusy(true);
            devChatOut.textContent = '';
            try {
                const message = (devMessage.value || '').trim();
                const payload = { message, mode: '<?php echo htmlspecialchars($mode, ENT_QUOTES, 'UTF-8'); ?>' };
                const r = await json('/hub/workbench/api/agent/chat/', payload);
                devChatOut.textContent = JSON.stringify(r, null, 2);
            } finally {
                setBusy(false);
            }
        });
    }

    function sse(url, onEvent) {
        const es = new EventSource(url);
        es.onmessage = (e) => onEvent('message', e.data);
        es.onerror = () => onEvent('error', null);
        es.addEventListener('job.created', (e) => onEvent('job.created', e.data));
        es.addEventListener('job.started', (e) => onEvent('job.started', e.data));
        es.addEventListener('job.step.started', (e) => onEvent('job.step.started', e.data));
        es.addEventListener('job.step.completed', (e) => onEvent('job.step.completed', e.data));
        es.addEventListener('job.completed', (e) => onEvent('job.completed', e.data));
        es.addEventListener('job.failed', (e) => onEvent('job.failed', e.data));
        return es;
    }

    if (btnCreateJob && devJobGoal && devJobOut) {
        btnCreateJob.addEventListener('click', async () => {
            setBusy(true);
            devJobOut.textContent = '';
            try {
                const text = (devJobGoal.value || '').trim();
                const payload = { input: { text }, task_type: 'coding' };
                const r = await json('/hub/workbench/api/agent/jobs/', payload);
                devJobOut.textContent = JSON.stringify(r, null, 2);
                const jobId = r && r.job_id ? String(r.job_id) : '';
                if (!jobId) return;
                const url = '/hub/workbench/api/agent/jobs/events.php?job_id=' + encodeURIComponent(jobId);
                const lines = [];
                sse(url, (type, data) => {
                    if (type === 'error') return;
                    try {
                        const parsed = data ? JSON.parse(data) : null;
                        lines.push({ type, data: parsed });
                    } catch {
                        lines.push({ type, data });
                    }
                    devJobOut.textContent = JSON.stringify(lines.slice(-30), null, 2);
                });
            } finally {
                setBusy(false);
            }
        });
    }

    async function loadPersonas() {
        const p = await json('/hub/workbench/api/personas.php');
        personaSelect.innerHTML = '';
        if (p && p.success && Array.isArray(p.personas)) {
            for (const it of p.personas) {
                const opt = document.createElement('option');
                opt.value = it.persona_id;
                opt.textContent = it.label || it.persona_id;
                if (it.selected) opt.selected = true;
                personaSelect.appendChild(opt);
            }
        }
    }

    personaSelect.addEventListener('change', async () => {
        setBusy(true);
        try {
            const persona_id = personaSelect.value;
            const r = await json('/hub/workbench/api/personas.php', { action: 'select', persona_id });
            out.textContent = JSON.stringify(r, null, 2);
        } finally {
            await loadPersonas();
            await refreshContext();
            setBusy(false);
        }
    });

    btnRefreshContext.addEventListener('click', async () => {
        setBusy(true);
        out.textContent = '';
        try {
            const ctx = await refreshContext();
            out.textContent = JSON.stringify(ctx, null, 2);
        } finally {
            await loadPersonas();
            setBusy(false);
        }
    });

    btnPlan.addEventListener('click', async () => {
        setBusy(true);
        out.textContent = '';
        try {
            const prompt = (promptEl.value || '').trim();
            const data = await json('/hub/workbench/api/plan.php', { prompt });
            out.textContent = JSON.stringify(data, null, 2);
        } finally {
            setBusy(false);
        }
    });

    if (btnRun) {
        btnRun.addEventListener('click', async () => {
            setBusy(true);
            out.textContent = '';
            try {
                const prompt = (promptEl.value || '').trim();
                const data = await json('/hub/workbench/api/execute.php', { prompt });
                out.textContent = JSON.stringify(data, null, 2);
            } finally {
                setBusy(false);
            }
        });
    }

    btnUploadAudio.addEventListener('click', () => audioFile.click());
    btnUploadImage.addEventListener('click', () => imageFile.click());

    async function upload(kind, file) {
        const fd = new FormData();
        fd.append('kind', kind);
        fd.append('file', file);
        const res = await fetch('/hub/workbench/api/inbox.php', { method: 'POST', body: fd });
        const text = await res.text();
        try { return JSON.parse(text); } catch { return { success: false, error: 'invalid_json', raw: text, status: res.status }; }
    }

    audioFile.addEventListener('change', async () => {
        const file = audioFile.files && audioFile.files[0];
        if (!file) return;
        setBusy(true);
        out.textContent = '';
        try {
            const r = await upload('audio', file);
            out.textContent = JSON.stringify(r, null, 2);
        } finally {
            audioFile.value = '';
            setBusy(false);
        }
    });

    imageFile.addEventListener('change', async () => {
        const file = imageFile.files && imageFile.files[0];
        if (!file) return;
        setBusy(true);
        out.textContent = '';
        try {
            const r = await upload('image', file);
            out.textContent = JSON.stringify(r, null, 2);
        } finally {
            imageFile.value = '';
            setBusy(false);
        }
    });

    if (btnListFiles) {
        btnListFiles.addEventListener('click', async () => {
            setBusy(true);
            filesOut.textContent = '';
            selectedFile = null;
            try {
                const r = await json('/hub/workbench/api/runtime.php', { action: 'list', path: '' });
                if (r && r.success && Array.isArray(r.files)) {
                    filesOut.textContent = r.files.map(f => f.path).join('\n');
                    selectedFile = r.files[0] && r.files[0].path ? r.files[0].path : null;
                } else {
                    filesOut.textContent = JSON.stringify(r, null, 2);
                }
            } finally {
                setBusy(false);
            }
        });
    }

    if (btnReadFile) {
        btnReadFile.addEventListener('click', async () => {
            if (!selectedFile) return;
            setBusy(true);
            out.textContent = '';
            try {
                const r = await json('/hub/workbench/api/runtime.php', { action: 'read', path: selectedFile });
                out.textContent = JSON.stringify(r, null, 2);
            } finally {
                setBusy(false);
            }
        });
    }

    (async () => {
        setBusy(true);
        try {
            await loadPersonas();
            const ctx = await refreshContext();
            out.textContent = JSON.stringify(ctx, null, 2);
        } finally {
            setBusy(false);
        }
    })();
</script>
</body>
</html>
