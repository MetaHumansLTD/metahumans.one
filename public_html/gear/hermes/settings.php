<?php
require_once __DIR__ . "/../../.cue/cue.php";
if (is_file(__DIR__ . "/../../auth/auth_functions.php")) {
    require_once __DIR__ . "/../../auth/auth_functions.php";
}
if (is_file(__DIR__ . "/../../auth/auth_classes.php")) {
    require_once __DIR__ . "/../../auth/auth_classes.php";
}
if (is_file(__DIR__ . "/../../auth/persona_registry.php")) {
    require_once __DIR__ . "/../../auth/persona_registry.php";
}
if (function_exists("security_startSecureSession")) {
    security_startSecureSession();
} elseif (function_exists("startSecureSession")) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION["mh_auth_user"])) {
    header("Location: /auth/login.php");
    exit;
}
function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
function mh_cfg_path(): string
{
    return dirname(dirname(__DIR__)) . "/ai/hermes.json";
}
function mh_read_cfg(): array
{
    $p = mh_cfg_path();
    if (!is_file($p)) {
        return [];
    }
    $raw = file_get_contents($p);
    if ($raw === false) {
        return [];
    }
    $cfg = json_decode($raw, true);
    return is_array($cfg) ? $cfg : [];
}
function mh_write_cfg(array $cfg): void
{
    $p = mh_cfg_path();
    $dir = dirname($p);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("Failed to encode config");
    }
    if (file_put_contents($p, $json . "\n") === false) {
        throw new RuntimeException("Failed to write config");
    }
    @chmod($p, 0600);
}
function mh_http_json(string $url, string $method = "GET", ?array $body = null, ?string $auth = null, int $timeout = 10): array
{
    $ch = curl_init();
    if ($ch === false) {
        return ["ok" => false, "status" => 0, "error" => "curl_init_failed"];
    }
    $headers = ["Accept: application/json"];
    if ($auth !== null && trim($auth) !== "") {
        $headers[] = "Authorization: " . trim($auth);
    }
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return ["ok" => false, "status" => 0, "error" => "json_encode_failed"];
        }
        $headers[] = "Content-Type: application/json";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ["ok" => false, "status" => $status, "error" => $err !== "" ? $err : "curl_exec_failed"];
    }
    $data = json_decode((string)$raw, true);
    return ["ok" => $status >= 200 && $status < 300, "status" => $status, "raw" => $raw, "json" => is_array($data) ? $data : null];
}
$cfg = mh_read_cfg();
$message = "";
$error = "";
$probeHealth = null;
$probeChat = null;
$probeModels = null;
$requestUri = isset($_SERVER["REQUEST_URI"]) ? (string)$_SERVER["REQUEST_URI"] : "/gear/hermes/settings.php";
if ($requestUri === "" || $requestUri[0] !== "/") {
    $requestUri = "/gear/hermes/settings.php";
}
$flash = $_SESSION["mh_hermes_settings_flash"] ?? null;
if (is_array($flash)) {
    $message = isset($flash["message"]) && is_string($flash["message"]) ? $flash["message"] : "";
    $error = isset($flash["error"]) && is_string($flash["error"]) ? $flash["error"] : "";
    $probeHealth = isset($flash["probeHealth"]) && is_array($flash["probeHealth"]) ? $flash["probeHealth"] : null;
    $probeChat = isset($flash["probeChat"]) && is_array($flash["probeChat"]) ? $flash["probeChat"] : null;
    $probeModels = isset($flash["probeModels"]) && is_array($flash["probeModels"]) ? $flash["probeModels"] : null;
}
unset($_SESSION["mh_hermes_settings_flash"]);
$templatesPath = function_exists("getTemplatesPath") ? getTemplatesPath() : (dirname(dirname(__DIR__)) . "/templates");
$baseUrl = (string)($cfg["base_url"] ?? "https://metahumans.one/hermes");
$apiKey = (string)($cfg["api_key"] ?? "");
$model = (string)($cfg["model"] ?? "hermes4-405b-api");
$timeoutSec = (int)($cfg["timeout_sec"] ?? 30);
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    try {
        $action = (string)($_POST["action"] ?? "save");
        $baseUrl = trim((string)($_POST["base_url"] ?? $baseUrl));
        $apiKey = trim((string)($_POST["api_key"] ?? $apiKey));
        $model = trim((string)($_POST["model"] ?? $model));
        $timeoutSec = (int)($_POST["timeout_sec"] ?? $timeoutSec);
        if ($baseUrl === "") {
            $baseUrl = "https://metahumans.one/hermes";
        }
        if ($model === "") {
            $model = "hermes4-405b-api";
        }
        if ($timeoutSec < 5) {
            $timeoutSec = 5;
        }
        $cfg["base_url"] = $baseUrl;
        $cfg["api_key"] = $apiKey;
        $cfg["model"] = $model;
        $cfg["timeout_sec"] = $timeoutSec;
        if ($action === "save" || $action === "probe") {
            mh_write_cfg($cfg);
            $message = "Saved";
        }
        if ($action === "probe") {
            $authHeader = $apiKey !== "" ? ("Bearer " . $apiKey) : null;

            $remoteHealthUrl = rtrim($baseUrl, "/") . "/healthz";

            if ($remoteHealthUrl !== "") {
                $probeHealth = mh_http_json($remoteHealthUrl, "GET", null, $authHeader, 8);
                $probeModels = mh_http_json(rtrim($baseUrl, "/") . "/v1/models", "GET", null, $authHeader, 8);
            }

            $selfHost = isset($_SERVER["HTTP_HOST"]) && is_string($_SERVER["HTTP_HOST"]) && trim($_SERVER["HTTP_HOST"]) !== "" ? ("https://" . (string)$_SERVER["HTTP_HOST"]) : "http://127.0.0.1";
            $localChatUrl = rtrim($selfHost, "/") . "/ai/chat.php";
            $probeChat = mh_http_json($localChatUrl, "POST", [
                "model" => $model,
                "messages" => [
                    ["role" => "user", "content" => "ping"],
                ],
            ], null, max(5, min(15, $timeoutSec)));
        }
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
    if (!headers_sent()) {
        $_SESSION["mh_hermes_settings_flash"] = [
            "message" => $message,
            "error" => $error,
            "probeHealth" => is_array($probeHealth) ? $probeHealth : null,
            "probeChat" => is_array($probeChat) ? $probeChat : null,
            "probeModels" => is_array($probeModels) ? $probeModels : null,
        ];
        header("Location: " . $requestUri, true, 303);
        exit;
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Hermes Settings</title>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-head.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-head.php";
}
?>
<style>
html,body{background-color:var(--background-color,#0a0a1a) !important;color:var(--text-color,#ffffff) !important;}
main{max-width:1100px;margin:0 auto;padding:18px 20px}
main.main-content .card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;margin:12px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}
main.main-content .field{display:grid;gap:6px;margin-bottom:10px}
main.main-content .label{font-size:12px;color:#bcd3f1}
main.main-content .input{width:100%;padding:10px 12px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.25);color:#e6f0ff}
main.main-content .row{display:flex;gap:10px;align-items:center}
main.main-content .btn{border-radius:10px;border:1px solid rgba(42,167,255,.55);background:rgba(42,167,255,.18);color:#e6f0ff;padding:10px 12px;font-weight:700;cursor:pointer}
main.main-content .msg{padding:10px 12px;border-radius:10px;margin:12px 0;border:1px solid rgba(33,208,122,.35);background:rgba(33,208,122,.12)}
main.main-content .err{padding:10px 12px;border-radius:10px;margin:12px 0;border:1px solid rgba(255,91,91,.35);background:rgba(255,91,91,.12)}
pre{white-space:pre-wrap;word-break:break-word;background:rgba(0,0,0,.20);border:1px solid rgba(255,255,255,.10);border-radius:10px;padding:10px;margin:0}
a{color:#7cc4ff;text-decoration:none}
a:hover{text-decoration:underline}
code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
</style>
</head>
<body>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-body-start.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-body-start.php";
}
?>
<main class="main-content">
<div class="row" style="justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap">
    <h1 style="margin:0">Hermes Settings</h1>
    <div class="row" style="gap:12px">
        <a class="btn" href="/control/brain">Brain Control</a>
        <a class="btn" href="/ai/chat.php" target="_blank" rel="noopener">Local Chat API</a>
    </div>
</div>
<?php if ($message !== ""): ?><div class="msg"><?php echo h($message); ?></div><?php endif; ?>
<?php if ($error !== ""): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>
<form method="post" action="">
<div class="grid">
    <div class="card">
        <h2>Endpoints</h2>
        <label class="field"><span class="label">Upstream base URL</span><input class="input" name="base_url" value="<?php echo h($baseUrl); ?>" placeholder="https://metahumans.one/hermes"></label>
        <label class="field"><span class="label">API key (optional)</span><input class="input" name="api_key" value="<?php echo h($apiKey); ?>" placeholder="sk-…"></label>
        <label class="field"><span class="label">Model</span><input class="input" name="model" value="<?php echo h($model); ?>" placeholder="hermes4-405b-api"></label>
        <label class="field"><span class="label">Timeout (seconds)</span><input class="input" name="timeout_sec" value="<?php echo h((string)$timeoutSec); ?>" inputmode="numeric"></label>
        <div class="row" style="margin-top:10px">
            <button class="btn" name="action" value="save" type="submit">Save</button>
            <button class="btn" name="action" value="probe" type="submit">Save + Probe</button>
        </div>
        <div style="margin-top:10px">
            <div class="label">Known URLs</div>
            <div><code><?php echo h(mh_cfg_path()); ?></code></div>
            <div><code><?php echo h("/ai/chat.php"); ?></code></div>
        </div>
    </div>
    <div class="card">
        <h2>Status</h2>
        <div class="label">Upstream /health</div>
        <pre><?php echo h($probeHealth !== null ? json_encode($probeHealth, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : "Click “Save + Probe”"); ?></pre>
        <div class="label" style="margin-top:10px">Models</div>
        <pre><?php echo h($probeModels !== null ? json_encode($probeModels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ""); ?></pre>
        <div class="label" style="margin-top:10px">Local /ai/chat.php</div>
        <pre><?php echo h($probeChat !== null ? json_encode($probeChat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ""); ?></pre>
    </div>
</div>
</form>
</main>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-body-end.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-body-end.php";
}
?>
</body>
</html>
