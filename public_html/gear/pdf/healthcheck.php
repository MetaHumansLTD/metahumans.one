<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/pdf_config.php';

$wantsJson = false;
if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
    $wantsJson = true;
} elseif (isset($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $wantsJson = true;
}

$cfg = mh_pdf_load_config();
$base = rtrim((string)($cfg['stirling_base_url'] ?? ''), '/');
if ($base === '') {
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['ok' => false, 'engine' => 'pdf-platform', 'error' => 'missing_config', 'base_url' => '']);
        exit;
    }
}

$engineOk = false;
$statusCode = 0;
$checkedUrl = '';
$error = '';

if ($base !== '') {
    $targets = [
        $base . '/api/v1/info/status',
        $base . '/swagger-ui/index.html',
    ];
    foreach ($targets as $t) {
        $ch = curl_init($t);
        $headers = [];
        $apiKey = (string)($cfg['stirling_api_key'] ?? '');
        if ($apiKey !== '') {
            $headers[] = 'X-API-KEY: ' . $apiKey;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $ch = null;

        $checkedUrl = $t;
        $statusCode = (int)$code;
        if ($body !== false && $statusCode >= 200 && $statusCode < 300) {
            $engineOk = true;
            $error = '';
            break;
        }
        $error = $body === false ? $err : ('HTTP ' . $statusCode);
    }
} else {
    $error = 'missing_config';
}

if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($engineOk ? 200 : 502);
    echo json_encode([
        'ok' => $engineOk,
        'engine' => 'pdf-platform',
        'base_url' => $base,
        'checked_url' => $checkedUrl,
        'status_code' => $statusCode,
        'error' => $engineOk ? null : $error,
    ]);
    exit;
}

$banner = $engineOk ? 'UP' : 'DOWN';
$bannerColor = $engineOk ? '#00d4ff' : '#ff4444';
$baseSafe = htmlspecialchars($base !== '' ? $base : '(not set)', ENT_QUOTES, 'UTF-8');
$checkedSafe = htmlspecialchars($checkedUrl !== '' ? $checkedUrl : '(not checked)', ENT_QUOTES, 'UTF-8');
$errorSafe = htmlspecialchars($error, ENT_QUOTES, 'UTF-8');

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Meta Humans PDF - Healthcheck</title>
    <style>
        :root { color-scheme: dark; }
        body { background: #050816; color: #fff; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, sans-serif; margin: 0; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 26px 18px 60px; }
        .card { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.10); border-radius: 18px; padding: 16px; }
        .row { display:flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: space-between; }
        .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.10); font-size: 0.85rem; color: rgba(255,255,255,0.75); }
        a { color: #00d4ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .k { color: rgba(255,255,255,0.65); width: 160px; display: inline-block; }
        .v { color: rgba(255,255,255,0.9); }
    </style>
</head>
<body>
<?php if (function_exists('renderGlobalHeader')) { renderGlobalHeader(); } ?>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div>
                <div style="font-family:'Orbitron',sans-serif; color:#00d4ff; font-size: 1.3rem; letter-spacing: 0.6px;">Meta Humans PDF</div>
                <div style="color: rgba(255,255,255,0.70); margin-top: 4px;">Healthcheck</div>
            </div>
            <div class="pill" style="border-color: <?php echo htmlspecialchars($bannerColor); ?>; color: <?php echo htmlspecialchars($bannerColor); ?>;"><?php echo htmlspecialchars($banner); ?></div>
        </div>
        <div style="margin-top: 14px; line-height: 1.7;">
            <div><span class="k">Base URL</span><span class="v"><?php echo $baseSafe; ?></span></div>
            <div><span class="k">Checked URL</span><span class="v"><?php echo $checkedSafe; ?></span></div>
            <div><span class="k">Status Code</span><span class="v"><?php echo htmlspecialchars((string)$statusCode); ?></span></div>
            <?php if (!$engineOk): ?>
                <div><span class="k">Error</span><span class="v"><?php echo $errorSafe; ?></span></div>
            <?php endif; ?>
        </div>
        <div style="margin-top: 14px;" class="row">
            <div class="pill"><a href="/gear/pdf/">Back</a></div>
            <div class="pill"><a href="/gear/pdf/settings.php">Settings</a></div>
            <div class="pill"><a href="/gear/pdf/healthcheck.php?format=json" target="_blank" rel="noreferrer">JSON</a></div>
        </div>
    </div>
</div>
<?php if (function_exists('renderGlobalFooter')) { renderGlobalFooter(); } ?>
</body>
</html>
