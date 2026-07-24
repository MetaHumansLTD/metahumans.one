<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/pdf_config.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$csrfToken = $security ? $security->generateCSRFToken('pdf-platform') : '';

$statusMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $ok = $security && $security->validateCSRFToken($token, 'pdf-platform');
    if (!$ok) {
        $errorMessage = 'Invalid CSRF token';
    } else {
        try {
            mh_pdf_save_config([
                'stirling_base_url' => (string)($_POST['stirling_base_url'] ?? ''),
                'stirling_api_key' => (string)($_POST['stirling_api_key'] ?? ''),
                'stirling_html_to_pdf_path' => (string)($_POST['stirling_html_to_pdf_path'] ?? ''),
            ]);
            $statusMessage = 'Settings saved';
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }
}

$cfg = mh_pdf_load_config();
$base = (string)($cfg['stirling_base_url'] ?? '');
$swagger = $base !== '' ? (rtrim($base, '/') . '/swagger-ui/index.html') : '';

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meta Humans PDF - Settings</title>
    <?php
    try {
        if (function_exists('includeNoticesWidget')) {
            includeNoticesWidget();
        }
    } catch (Throwable $e) {
    }
    ?>
    <style>
        :root { color-scheme: dark; }
        body { background: #050816; color: #fff; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, sans-serif; margin: 0; }
        .wrap { max-width: 1200px; margin: 0 auto; padding: 26px 18px 60px; }
        .card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.10); border-radius: 18px; padding: 16px; }
        .row { display:flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        label { display:block; font-size: 0.9rem; color: rgba(255,255,255,0.70); margin-bottom: 6px; }
        input { width:100%; background: rgba(0,0,0,0.25); color:#fff; border:1px solid rgba(255,255,255,0.18); border-radius: 12px; padding: 10px 12px; }
        .btn { background: linear-gradient(45deg, #00d4ff, #7c3aed); border: none; color: #000; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn.secondary { background: rgba(255,255,255,0.08); color: #fff; border: 1px solid rgba(255,255,255,0.18); }
        .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.10); font-size: 0.85rem; color: rgba(255,255,255,0.75); }
        a { color: #00d4ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<?php if (function_exists('renderGlobalHeader')) { renderGlobalHeader(); } ?>

<div class="wrap">
    <div class="card">
        <div class="row" style="justify-content: space-between;">
            <div>
                <div style="font-family:'Orbitron',sans-serif; color:#00d4ff; font-size: 1.3rem; letter-spacing: 0.6px;">Meta Humans PDF</div>
                <div style="color: rgba(255,255,255,0.70); margin-top: 4px;">Connection settings</div>
            </div>
            <div class="row">
                <a class="pill" href="/gear/pdf/">Back to PDF</a>
                <?php if ($base !== ''): ?>
                    <a class="pill" href="<?php echo htmlspecialchars($base); ?>" target="_blank" rel="noreferrer">Open UI</a>
                <?php endif; ?>
                <?php if ($swagger !== ''): ?>
                    <a class="pill" href="<?php echo htmlspecialchars($swagger); ?>" target="_blank" rel="noreferrer">API Docs</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top: 16px;">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <div style="margin-top: 10px;">
                <label>Base URL</label>
                <input name="stirling_base_url" value="<?php echo htmlspecialchars((string)($cfg['stirling_base_url'] ?? '')); ?>" placeholder="https://pdf.metahumans.one">
            </div>
            <div style="margin-top: 10px;">
                <label>API Key (optional)</label>
                <input name="stirling_api_key" value="<?php echo htmlspecialchars((string)($cfg['stirling_api_key'] ?? '')); ?>" placeholder="">
            </div>
            <div style="margin-top: 10px;">
                <label>HTML → PDF API Path</label>
                <input name="stirling_html_to_pdf_path" value="<?php echo htmlspecialchars((string)($cfg['stirling_html_to_pdf_path'] ?? '')); ?>" placeholder="/api/v1/convert/html/pdf">
            </div>
            <div class="row" style="margin-top: 12px;">
                <button class="btn" type="submit">Save</button>
                <a class="btn secondary" href="/gear/pdf/healthcheck.php" target="_blank" rel="noreferrer" style="text-decoration:none;">Healthcheck</a>
            </div>
        </form>
    </div>
</div>

<?php if (function_exists('renderGlobalFooter')) { renderGlobalFooter(); } ?>
<script>
function mhPdfNotice(message, type) {
    try {
        var inst = window.popupNotice || window.globalPopupNotice || null;
        if (!inst && typeof window.PopupNotice !== 'undefined') {
            inst = new window.PopupNotice();
            window.popupNotice = inst;
        }
        if (inst && typeof inst.show === 'function') {
            inst.show(message, type || 'info');
        }
    } catch (_) {}
}
document.addEventListener('DOMContentLoaded', function () {
    <?php if (is_string($statusMessage) && $statusMessage !== ''): ?>
    mhPdfNotice(<?php echo json_encode($statusMessage); ?>, 'success');
    <?php endif; ?>
    <?php if (is_string($errorMessage) && $errorMessage !== ''): ?>
    mhPdfNotice(<?php echo json_encode($errorMessage); ?>, 'error');
    <?php endif; ?>
});
</script>
</body>
</html>
