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
    <title>Genesis: Orientation</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
    <style>
        body { background: #0a0a0a; color: #00d4ff; font-family: 'Rajdhani', sans-serif; margin: 0; }
        .genesis-page { display: flex; justify-content: center; padding: 15px; }
        .container { background: rgba(255,255,255,0.05); padding: 40px; border-radius: 12px; border: 1px solid #333; text-align: center; max-width: 600px; width: 100%; box-shadow: 0 0 20px rgba(0, 212, 255, 0.1); }
        h1 { margin-bottom: 20px; font-weight: 300; letter-spacing: 2px; }
        .persona-box { background: #000; height: 200px; margin: 20px 0; display: flex; align-items: center; justify-content: center; border: 1px dashed #333; color: #555; }
        button { background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%); border: none; padding: 15px 30px; color: white; font-weight: bold; border-radius: 6px; cursor: pointer; font-size: 16px; transition: transform 0.2s; }
        button:hover { transform: scale(1.05); }
    </style>
</head>
<body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
    <div class="genesis-page">
    <div class="container">
        <h1>SYSTEM ORIENTATION</h1>
        <div class="persona-box">
            [ PERSONA AVATAR STREAM WOULD APPEAR HERE ]
        </div>
        <p>"I am your Meta Human Persona. I exist to serve as your bridge to the digital ecosystem. I manage your assets, schedule, and code."</p>
        <button onclick="finishExplanation()">ACKNOWLEDGE & CONTINUE</button>
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

        function finishExplanation() {
            fetch('action.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'action=complete_explanation'
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
