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
$templatesPath = function_exists("getTemplatesPath") ? getTemplatesPath() : (dirname(dirname(__DIR__)) . "/templates");
function h(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES); }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Brain Control</title>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-head.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-head.php";
}
?>
<style>
html,body{background-color:var(--background-color,#0a0a1a) !important;color:var(--text-color,#ffffff) !important;}
main{max-width:1100px;margin:0 auto;padding:18px 20px}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;margin:12px 0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px}
.pill{display:inline-flex;gap:8px;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.18);font-size:12px;color:#bcd3f1}
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
<h1 style="margin:0 0 10px 0">Brain Control</h1>
<div class="grid">
    <div class="card">
        <h2 style="margin:0 0 10px 0">Hermes</h2>
        <div class="pill">Settings UI</div>
        <div style="margin-top:10px"><a href="/gear/hermes/settings.php">/gear/hermes/settings.php</a></div>
        <div style="margin-top:10px" class="pill">GPU server API: <code>https://meta.superhumans.one/hermes/…</code></div>
    </div>
    <div class="card">
        <h2 style="margin:0 0 10px 0">Tock</h2>
        <div class="pill">Settings UI</div>
        <div style="margin-top:10px"><a href="/gear/tock/settings.php">/gear/tock/settings.php</a></div>
        <div style="margin-top:10px" class="pill">GPU server API: <code>https://meta.superhumans.one/tock/…</code></div>
    </div>
</div>
</main>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-body-end.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-body-end.php";
}
?>
</body>
</html>
