<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$user = $_SESSION['mh_auth_user'] ?? '';
if (!is_string($user) || trim($user) === '') {
    header('Location: /auth/login.php?redirect=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/hub/meet/meeting_share.php'), true, 302);
    exit;
}

$roomId = isset($_GET['room_id']) ? trim((string)$_GET['room_id']) : '';
if ($roomId === '') {
    http_response_code(400);
    echo 'Missing room_id';
    exit;
}

$embed = isset($_GET['embed']) && (string)$_GET['embed'] === '1';
$title = 'MetaHumans Meeting';
$date = isset($_GET['date']) ? (string)$_GET['date'] : '';
$time = isset($_GET['time']) ? (string)$_GET['time'] : '';
$shareDescription = 'Join this MetaHumans meeting';
if ($date !== '' || $time !== '') {
    $shareDescription .= ' on';
    if ($date !== '') {
        $shareDescription .= ' ' . $date;
    }
    if ($time !== '') {
        $shareDescription .= ' at ' . $time;
    }
}
$shareDescription .= ':';

$host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string)$_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one');
$host = trim(explode(',', $host)[0]);
$scheme = 'https';
$baseJoinUrl = $scheme . '://' . $host . '/meet.php?room_id=' . urlencode($roomId);
$presenterUrl = $baseJoinUrl . '&role=presenter';
$participantUrl = $baseJoinUrl . '&role=viewer';
$currentUrl = $participantUrl;
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($currentUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share MetaHumans Meeting</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #050816;
            color: #f9fafb;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            overflow-x: hidden;
        }
        body.embed {
            background: transparent;
            display: block;
            min-height: 0;
            padding: 0;
        }
        .card {
            background: #020617;
            border-radius: 16px;
            padding: 24px 24px 28px;
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.8);
            max-width: 420px;
            width: 100%;
            text-align: center;
            margin: 0 auto;
        }
        .card.embed {
            background: transparent;
            box-shadow: none;
            padding: 0;
            border-radius: 0;
            max-width: none;
            width: 100%;
        }
        .card.embed h1,
        .card.embed .subtitle,
        .card.embed .hint {
            display: none;
        }
        .card.embed .meta-line {
            margin-bottom: 8px;
        }
        .card.embed .qr-wrapper {
            background: transparent;
            padding: 0;
            margin-bottom: 12px;
        }
        .card.embed .input-row {
            width: 100%;
            max-width: 420px;
            margin: 0 auto 12px;
        }
        .card.embed .actions {
            margin-top: 0;
        }
        h1 {
            font-size: 1.4rem;
            margin: 0 0 4px;
        }
        .subtitle {
            font-size: 0.9rem;
            color: #9ca3af;
            margin-bottom: 16px;
        }
        .meta-line {
            font-size: 0.85rem;
            color: #e5e7eb;
            margin-bottom: 12px;
        }
        .qr-wrapper {
            background: #020617;
            border-radius: 12px;
            padding: 12px;
            display: inline-block;
            margin-bottom: 16px;
        }
        img.qr {
            display: block;
            width: 220px;
            height: 220px;
        }
        .join-url {
            font-size: 0.8rem;
            color: #22c55e;
            word-break: break-all;
            margin-bottom: 16px;
        }
        .input-row {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }
        .input-row input {
            flex: 1;
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid #1f2937;
            background: #020617;
            color: #e5e7eb;
            font-size: 0.85rem;
        }
        .input-row button {
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            background: #4f46e5;
            color: white;
            font-size: 0.85rem;
            cursor: pointer;
        }
        .input-row button:hover {
            background: #4338ca;
        }
        .role-toggle {
            display: none;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: center;
            margin-top: 8px;
        }
        .actions button {
            padding: 8px 14px;
            border-radius: 999px;
            border: none;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .primary {
            background: #22c55e;
            color: #022c22;
        }
        .primary:hover {
            background: #16a34a;
        }
        .secondary {
            background: #111827;
            color: #e5e7eb;
        }
        .secondary:hover {
            background: #1f2937;
        }
        .hint {
            font-size: 0.75rem;
            color: #9ca3af;
            margin-top: 12px;
        }
    </style>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
    <div class="card<?php echo $embed ? ' embed' : ''; ?>">
        <h1>Share the meeting</h1>
        <div class="subtitle">Scan the QR code or share the link below</div>
        <?php if ($date !== '' || $time !== ''): ?>
            <div class="meta-line">
                <?php if ($date !== ''): ?>
                    <span><?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if ($date !== '' && $time !== ''): ?>
                    <span> • </span>
                <?php endif; ?>
                <?php if ($time !== ''): ?>
                    <span><?php echo htmlspecialchars($time, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="qr-wrapper">
            <img id="qr-image" class="qr" src="<?php echo htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Meeting QR code">
        </div>

        <div id="join-url" class="join-url"><?php echo htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8'); ?></div>

        <div class="input-row">
            <input id="meeting-link" type="text" readonly value="<?php echo htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="button" onclick="copyLink()">Copy</button>
        </div>
        <div class="actions">
            <button class="primary" type="button" onclick="openMeeting()">Open meeting</button>
            <button class="secondary" type="button" onclick="shareNative()">Share...</button>
        </div>

        <div class="hint">
            Use the buttons above to copy or share this meeting link with others.
        </div>
    </div>

    <script>
        const presenterUrl = "<?php echo htmlspecialchars($presenterUrl, ENT_QUOTES, 'UTF-8'); ?>";
        const participantUrl = "<?php echo htmlspecialchars($participantUrl, ENT_QUOTES, 'UTF-8'); ?>";
        const qrBase = "https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=";
        let currentLink = participantUrl;

        function copyLink() {
            const input = document.getElementById('meeting-link');
            const fullText = shareText + ' ' + currentLink;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(fullText).catch(() => {
                    const previousValue = input.value;
                    input.value = fullText;
                    input.select();
                    input.setSelectionRange(0, 99999);
                    document.execCommand('copy');
                    input.value = previousValue;
                });
            } else {
                const previousValue = input.value;
                input.value = fullText;
                input.select();
                input.setSelectionRange(0, 99999);
                document.execCommand('copy');
                input.value = previousValue;
            }
        }

        const shareText = "<?php echo htmlspecialchars($shareDescription, ENT_QUOTES, 'UTF-8'); ?>";

        function openMeeting() {
            window.location.href = currentLink;
        }

        function shareNative() {
            if (navigator.share) {
                navigator.share({
                    title: "<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>",
                    text: shareText,
                    url: currentLink
                }).catch(() => {});
            } else {
                copyLink();
            }
        }

        // Role toggle is disabled on this screen; links always point to participantUrl.
    </script>
</body>
</html>
