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
    <title>Genesis: Create Persona</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
    <style>
        body.genesis-create main.main-content {
            display: flex;
            padding: 0;
        }
        body.genesis-create .genesis-page {
            flex: 1;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 0 15px;
            color: var(--theme-primary, #00d4ff);
            font-family: var(--font-primary, 'Rajdhani', sans-serif);
        }
        body.genesis-create .genesis-card {
            background: rgba(255,255,255,0.05);
            padding: 40px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.12);
            text-align: center;
            max-width: 520px;
            width: 100%;
            box-shadow: var(--shadow-card, 0 0 20px rgba(0, 212, 255, 0.1));
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        body.genesis-create .genesis-card h1 { margin-bottom: 20px; font-weight: 300; letter-spacing: 2px; }
        body.genesis-create .genesis-card p { color: rgba(255,255,255,0.7); margin-bottom: 30px; line-height: 1.6; }
        body.genesis-create .genesis-card button { background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%); border: none; padding: 15px 30px; color: white; font-weight: bold; border-radius: 6px; cursor: pointer; font-size: 16px; transition: transform 0.2s; }
        body.genesis-create .genesis-card button:hover { transform: scale(1.05); }
    </style>
</head>
<body class="genesis-create">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
    <div class="genesis-page">
    <div class="genesis-card">
        <h1>GENESIS PROTOCOL</h1>
        <p>Welcome, User. To begin your journey, we must instantiate your digital twin.</p>
        <p>Your Persona ID will be based on your registration identity.</p>
        <button onclick="createPersona()">Initialize Meta Human Persona</button>
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

        function createPersona() {
            fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=complete_persona'
            })
            .then(r => r.json())
            .then(d => {
                if(d.success) window.location.href = d.next;
                else mhNotice('Error: ' + (d.error || 'Unknown error'), 'error');
            });
        }
    </script>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
