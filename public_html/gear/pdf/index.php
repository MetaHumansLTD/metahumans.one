
<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/pdf_config.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cfg = mh_pdf_load_config();
$base = (string)($cfg['stirling_base_url'] ?? '');
$swagger = $base !== '' ? (rtrim($base, '/') . '/swagger-ui/index.html') : '';

if ($base !== '' && !isset($_GET['embed'])) {
    header('Location: ' . $base);
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meta Humans PDF</title>
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
        .wrap { max-width: 1600px; margin: 0 auto; padding: 16px 12px 24px; }
        .card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.10); border-radius: 18px; padding: 16px; }
        .row { display:flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn { background: linear-gradient(45deg, #00d4ff, #7c3aed); border: none; color: #000; border-radius: 12px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn.secondary { background: rgba(255,255,255,0.08); color: #fff; border: 1px solid rgba(255,255,255,0.18); }
        .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.10); font-size: 0.85rem; color: rgba(255,255,255,0.75); }
        a { color: #00d4ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .frame { width: 100%; height: calc(100vh - 210px); border: 1px solid rgba(255,255,255,0.10); border-radius: 18px; overflow: hidden; background: rgba(255,255,255,0.02); }
        .frame iframe { width: 100%; height: 100%; border: 0; display: block; background: #fff; }
    </style>
</head>
<body>
<?php if (function_exists('renderGlobalHeader')) { renderGlobalHeader(); } ?>

<div class="wrap">
    <div class="card">
        <div class="row" style="justify-content: space-between;">
            <div>
                <div style="font-family:'Orbitron',sans-serif; color:#00d4ff; font-size: 1.3rem; letter-spacing: 0.6px;">Meta Humans PDF</div>
            </div>
            <div class="row">
                <a class="pill" href="/gear/pdf/settings.php">Settings</a>
                <?php if ($base !== ''): ?>
                    <a class="pill" href="<?php echo htmlspecialchars($base); ?>" target="_blank" rel="noreferrer">Open UI</a>
                <?php endif; ?>
                <?php if ($swagger !== ''): ?>
                    <a class="pill" href="<?php echo htmlspecialchars($swagger); ?>" target="_blank" rel="noreferrer">API Docs</a>
                <?php endif; ?>
                <a class="pill" href="/gear/pdf/healthcheck.php" target="_blank" rel="noreferrer">Healthcheck</a>
            </div>
        </div>
    </div>

    <?php if ($base === ''): ?>
        <div class="card" style="margin-top: 16px;">
            <div style="color: rgba(255,255,255,0.80); line-height: 1.5;">
                Base URL is not configured yet.
                <a href="/gear/pdf/settings.php">Open settings</a>.
            </div>
        </div>
    <?php else: ?>
        <div class="frame" style="margin-top: 16px;">
            <iframe src="<?php echo htmlspecialchars($base); ?>" title="Meta Humans PDF"></iframe>
        </div>
    <?php endif; ?>
</div>

<?php if (function_exists('renderGlobalFooter')) { renderGlobalFooter(); } ?>
</body>
</html>
