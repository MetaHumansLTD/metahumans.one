<?php
require_once __DIR__ . "/../../.cue/cue.php";
require_once __DIR__ . "/../../auth/auth_functions.php";

if (function_exists("startSecureSession")) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION["mh_auth_user"])) {
    header("Location: /auth/login.php");
    exit;
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$username = trim((string)($_SESSION["mh_auth_user"] ?? ""));
$tenantId = (string)($_SESSION["mh_tenant_id"] ?? "");
if ($tenantId === "" && $username !== "") {
    $tenantId = "user:" . $username;
}

function mh_vb_b64url_encode(string $raw): string
{
    $b64 = base64_encode($raw);
    $b64 = is_string($b64) ? $b64 : '';
    return rtrim(strtr($b64, '+/', '-_'), '=');
}

function mh_vb_sign_tenant_token(string $tenantId, string $username, int $ttlSeconds = 600): string
{
    $keyPath = '/data/security/app.key';
    $secret = is_file($keyPath) ? trim((string)@file_get_contents($keyPath)) : '';
    if ($secret === '') {
        return '';
    }
    $exp = time() + max(60, $ttlSeconds);
    $payload = json_encode([
        'tid' => strtolower(trim($tenantId)),
        'u' => trim($username),
        'exp' => $exp,
        'n' => bin2hex(random_bytes(8)),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return '';
    }
    $p = mh_vb_b64url_encode($payload);
    $sig = hash_hmac('sha256', $p, $secret, true);
    return $p . '.' . mh_vb_b64url_encode($sig);
}

$tenantCookie = strtolower($tenantId);
if ($tenantCookie !== "") {
    setcookie("mh_tenant_id", $tenantCookie, [
        "expires" => time() + 86400,
        "path" => "/",
        "secure" => true,
        "httponly" => true,
        "samesite" => "Strict",
    ]);
    setcookie("mh_user", $username, [
        "expires" => time() + 86400,
        "path" => "/",
        "secure" => true,
        "httponly" => true,
        "samesite" => "Strict",
    ]);

    $token = mh_vb_sign_tenant_token($tenantId, $username, 600);
    if ($token !== '') {
        setcookie("mh_vb_token", $token, [
            "expires" => time() + 600,
            "path" => "/voicebox",
            "secure" => true,
            "httponly" => true,
            "samesite" => "Strict",
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voicebox</title>
    <?php include_once getTemplatesPath() . "/global-ui/includes/complete-head.php"; ?>
    <style>
        body.voicebox-webui main.main-content { padding: 0; }
        body.voicebox-webui .wrap { max-width: 1280px; margin: 0 auto; padding: 18px 24px; box-sizing: border-box; }
        body.voicebox-webui .meta { display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom: 12px; }
        body.voicebox-webui .pill { display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04); font-size:12px; color: rgba(230,246,255,.9); }
        body.voicebox-webui .frame { width: 100%; height: calc(100vh - 220px); border: 1px solid rgba(255,255,255,.14); border-radius: 14px; overflow: hidden; background: rgba(0,0,0,.15); box-shadow: var(--shadow-card, 0 0 20px rgba(0, 212, 255, 0.08)); }
        body.voicebox-webui iframe { width: 100%; height: 100%; border: 0; }
    </style>
</head>
<body class="voicebox-webui">
<?php include_once getTemplatesPath() . "/global-ui/includes/complete-body-start.php"; ?>
<main class="main-content">
    <div class="wrap">
        <div class="meta">
            <div>
                <h1 style="margin:0">Voicebox</h1>
                <div style="margin-top:6px; opacity:.85; font-size:12px;">Local-first voice studio (TTS + voice cloning) running on metahumans.one.</div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end">
                <span class="pill">user: <?php echo h($username); ?></span>
                <span class="pill">tenant: <?php echo h($tenantId); ?></span>
            </div>
        </div>
        <div class="frame">
            <iframe src="/voicebox/?mh_tenant_id=<?php echo rawurlencode($tenantCookie); ?>&v=<?php echo (int)filemtime(__FILE__); ?>" allow="microphone; autoplay; clipboard-write" allowfullscreen></iframe>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . "/global-ui/includes/complete-body-end.php"; ?>
</body>
</html>
