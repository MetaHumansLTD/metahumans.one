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
    $redirect = '/hub/ide/';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}
$needRefresh = false;
foreach (['mh_auth_role', 'mh_user_internal_id', 'mh_device_id'] as $k) {
    if (!isset($_SESSION[$k]) || $_SESSION[$k] === '' || $_SESSION[$k] === null) {
        $needRefresh = true;
        break;
    }
}
if ($needRefresh) {
    $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/hub/ide/';
    if ($redirect === '' || $redirect[0] !== '/') $redirect = '/hub/ide/';
    header('Location: /auth/refresh_session.php?redirect=' . rawurlencode($redirect), true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meta Humans IDE</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/hub/ide/assets/ide.css?v=1">
</head>
<body class="mh-ide-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="mh-ide-shell">
        <header class="mh-ide-topbar">
            <div class="mh-ide-brand">Meta Humans IDE</div>
            <div class="mh-ide-topbar-right">
                <div class="mh-ide-pill" id="mhUserPill"></div>
                <div class="mh-ide-pill" id="mhPersonaPill"></div>
                <a class="mh-ide-link" href="/hub/workbench/?mode=develop">Workbench</a>
            </div>
        </header>

        <div class="mh-ide-body">
            <aside class="mh-ide-sidebar">
                <div class="mh-ide-panel">
                    <div class="mh-ide-panel-title">Context</div>
                    <div class="mh-ide-kv">
                        <div class="mh-ide-k">Tenant</div>
                        <div class="mh-ide-v" id="mhTenant"></div>
                        <div class="mh-ide-k">User</div>
                        <div class="mh-ide-v" id="mhUser"></div>
                        <div class="mh-ide-k">Persona</div>
                        <div class="mh-ide-v" id="mhPersona"></div>
                    </div>
                </div>
                <div class="mh-ide-panel">
                    <div class="mh-ide-panel-title">Workspace</div>
                    <div class="mh-ide-muted" id="mhWorkspaceRoot"></div>
                </div>
                <div class="mh-ide-panel">
                    <div class="mh-ide-panel-title">Actions</div>
                    <div class="mh-ide-actions">
                        <button class="mh-ide-btn" id="mhRefresh">Refresh</button>
                        <button class="mh-ide-btn" id="mhRemember">Remember</button>
                    </div>
                </div>
                <div class="mh-ide-panel">
                    <div class="mh-ide-panel-title">Headless Job</div>
                    <input class="mh-ide-textfield" id="mhJobRepoUrl" type="text" placeholder="Repo URL (optional, enables Hide)…" />
                    <input class="mh-ide-textfield" id="mhJobRepoCommit" type="text" placeholder="Commit/branch (optional)…" />
                    <textarea class="mh-ide-input mh-ide-input-small" id="mhJobGoal" placeholder="Describe a task to run as a headless job…"></textarea>
                    <div class="mh-ide-actions">
                        <button class="mh-ide-btn" id="mhJobVoice">Voice</button>
                        <button class="mh-ide-btn" id="mhJobVision">Vision</button>
                        <button class="mh-ide-btn" id="mhJobCreate">Create Job</button>
                        <button class="mh-ide-btn" id="mhJobStop" disabled>Stop Stream</button>
                    </div>
                    <div class="mh-ide-muted" id="mhJobMeta"></div>
                    <pre class="mh-ide-pre" id="mhJobOut"></pre>
                </div>
                <div class="mh-ide-panel">
                    <div class="mh-ide-panel-title">Meeting Bot</div>
                    <input class="mh-ide-textfield" id="mhMeetRoomId" type="text" placeholder="PlugNMeet room_id…" />
                    <input class="mh-ide-textfield" id="mhMeetBotName" type="text" placeholder="Bot name…" />
                    <div class="mh-ide-actions">
                        <button class="mh-ide-btn" id="mhMeetStart">Start</button>
                        <button class="mh-ide-btn" id="mhMeetStop">Stop</button>
                        <button class="mh-ide-btn" id="mhMeetStatus">Status</button>
                    </div>
                    <div class="mh-ide-muted" id="mhMeetOut"></div>
                </div>
            </aside>

            <section class="mh-ide-main">
                <div class="mh-ide-split">
                    <div class="mh-ide-editor">
                        <div class="mh-ide-panel-title">Editor</div>
                        <div class="mh-ide-placeholder">Monaco integration will render here.</div>
                    </div>
                    <div class="mh-ide-terminal">
                        <div class="mh-ide-panel-title">Terminal</div>
                        <div class="mh-ide-placeholder">xterm.js integration will render here.</div>
                    </div>
                </div>
            </section>

            <aside class="mh-ide-chat">
                <div class="mh-ide-chat-header">
                    <div class="mh-ide-panel-title">Chat</div>
                    <div class="mh-ide-muted" id="mhMemoryStats"></div>
                </div>
                <div class="mh-ide-chat-log" id="mhChatLog"></div>
                <div class="mh-ide-chat-compose">
                    <textarea class="mh-ide-input" id="mhChatInput" placeholder="Message your Persona…"></textarea>
                    <div class="mh-ide-actions">
                        <button class="mh-ide-btn" id="mhVoice">Voice</button>
                        <button class="mh-ide-btn" id="mhVision">Vision</button>
                        <button class="mh-ide-btn" id="mhSend">Send</button>
                    </div>
                    <div class="mh-ide-muted" id="mhInputStatus"></div>
                    <input id="mhVoiceFile" type="file" accept="audio/*" style="display:none" />
                    <input id="mhVisionFile" type="file" accept="image/*" style="display:none" />
                    <div class="mh-ide-error" id="mhError"></div>
                </div>
            </aside>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<div class="mh-ide-overlay" id="mhCaptureOverlay" aria-hidden="true">
    <div class="mh-ide-overlay-card">
        <div class="mh-ide-overlay-title">Live Camera</div>
        <div class="mh-ide-overlay-row">
            <label class="mh-ide-label" for="mhCameraDevice">Camera</label>
            <select class="mh-ide-select" id="mhCameraDevice"></select>
        </div>
        <div class="mh-ide-overlay-row">
            <label class="mh-ide-label" for="mhMicDevice">Microphone</label>
            <select class="mh-ide-select" id="mhMicDevice"></select>
        </div>
        <div class="mh-ide-overlay-row">
            <label class="mh-ide-check"><input type="checkbox" id="mhAttachFrameOnVoice" checked /> Attach a camera frame when voice stops</label>
        </div>
        <div class="mh-ide-overlay-row">
            <label class="mh-ide-check"><input type="checkbox" id="mhAutoSendOnVoice" /> Live feed mode (auto-send voice+frame to Persona)</label>
        </div>
        <video class="mh-ide-video" id="mhCameraPreview" autoplay playsinline muted></video>
        <div class="mh-ide-actions mh-ide-overlay-actions">
            <button class="mh-ide-btn" id="mhCameraCapture">Capture</button>
            <button class="mh-ide-btn" id="mhCameraClose">Close</button>
        </div>
        <div class="mh-ide-muted" id="mhCaptureHint"></div>
    </div>
</div>
<script src="/hub/ide/assets/ide.js?v=1"></script>
</body>
</html>
