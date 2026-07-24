<?php
declare(strict_types=1);

require_once __DIR__ . "/../../.cue/cue.php";
require_once __DIR__ . "/../../auth/auth_functions.php";
require_once __DIR__ . "/../../.cue/multica.php";

if (function_exists("startSecureSession")) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");

if (!isset($_SESSION["mh_auth_user"])) {
    $redirect = $_SERVER["REQUEST_URI"] ?? "/hub/";
    if (!is_string($redirect) || $redirect == "" || $redirect[0] !== "/") {
        $redirect = "/hub/";
    }
    header("Location: /auth/login.php?redirect=" . rawurlencode($redirect));
    exit;
}

$tenantId = isset($_SESSION["mh_tenant_id"]) && is_string($_SESSION["mh_tenant_id"]) ? trim((string)$_SESSION["mh_tenant_id"]) : "";
if ($tenantId === "") {
    $tenantId = "user:" . (string)$_SESSION["mh_auth_user"];
}

$username = isset($_SESSION["mh_auth_user"]) && is_string($_SESSION["mh_auth_user"]) ? trim((string)$_SESSION["mh_auth_user"]) : $tenantId;

if (function_exists("mh_apply_tenant_context")) {
    try { mh_apply_tenant_context($tenantId); } catch (Throwable $e) {}
}

$selfhostOrigin = mh_multica_selfhost_origin();
$workspace = mh_multica_workspace_slug($tenantId);
$boardPath = "/" . rawurlencode($workspace) . "/issues";
$boardUrl = $selfhostOrigin . $boardPath;

$host = isset($_SERVER["HTTP_HOST"]) && is_string($_SERVER["HTTP_HOST"]) ? (string)$_SERVER["HTTP_HOST"] : "metahumans.one";
if ($host === "") $host = "metahumans.one";
if (str_contains($host, ":") && !str_starts_with($host, "[")) {
    $host = explode(":", $host, 2)[0];
    if ($host === "") $host = "metahumans.one";
}
$hubReturnUrl = "https://" . $host . "/hub/agents/multi.php?sso=1";

$ts = time();
$sig = mh_multica_sso_signature($ts, $tenantId, $username);
$ssoUrlReturnHub = $selfhostOrigin
    . "/auth/mh?ts=" . rawurlencode((string)$ts)
    . "&tenant_id=" . rawurlencode($tenantId)
    . "&user=" . rawurlencode($username)
    . "&sig=" . rawurlencode($sig)
    . "&return=" . rawurlencode($hubReturnUrl);
$ssoUrlReturnBoard = $selfhostOrigin
    . "/auth/mh?ts=" . rawurlencode((string)$ts)
    . "&tenant_id=" . rawurlencode($tenantId)
    . "&user=" . rawurlencode($username)
    . "&sig=" . rawurlencode($sig)
    . "&return=" . rawurlencode($boardPath);

$didSSO = isset($_GET["sso"]) && is_string($_GET["sso"]) && $_GET["sso"] === "1";
if (!$didSSO) {
    header("Location: " . $ssoUrlReturnHub);
    exit;
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multica</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.multica-webui main.main-content { padding: 0; }
        body.multica-webui .wrap { max-width: 1280px; margin: 0 auto; padding: 18px 24px; box-sizing: border-box; }
        body.multica-webui .meta { display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin-bottom: 12px; }
        body.multica-webui .pill { display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.04); font-size:12px; color: rgba(230,246,255,.9); }
        body.multica-webui .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
        body.multica-webui .btn { display:inline-flex; align-items:center; gap:8px; border-radius:999px; padding: 8px 12px; border: 1px solid var(--theme-primary, #00d4ff); color: var(--theme-primary, #00d4ff); font-weight: 900; letter-spacing: 1px; font-family: 'Orbitron', sans-serif; background: transparent; text-decoration: none; }
        body.multica-webui .btn:hover { text-decoration: underline; }
        body.multica-webui .frame { width: 100%; height: calc(100vh - 220px); border: 1px solid rgba(255,255,255,.14); border-radius: 14px; overflow: hidden; background: rgba(0,0,0,.15); box-shadow: var(--shadow-card, 0 0 20px rgba(0, 212, 255, 0.08)); }
        body.multica-webui iframe { width: 100%; height: 100%; border: 0; }
    </style>
</head>
<body class="hub-page multica-webui">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="wrap">
        <div class="meta">
            <div>
                <h1 style="margin:0">Multica</h1>
                <div style="margin-top:6px; opacity:.85; font-size:12px;">Issue board for your workspace.</div>
            </div>
            <div class="actions">
                <span class="pill">user: <?php echo h($username); ?></span>
                <span class="pill">tenant: <?php echo h($tenantId); ?></span>
                <span class="pill">workspace: <?php echo h($workspace); ?></span>
                <a class="btn" href="<?php echo h($ssoUrlReturnBoard); ?>" target="_blank" rel="noopener">Open Board</a>
            </div>
        </div>
        <div class="frame">
            <iframe src="<?php echo h($boardUrl); ?>" allow="clipboard-read; clipboard-write" allowfullscreen></iframe>
        </div>
    </div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
