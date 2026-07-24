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
<title>Brain</title>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-head.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-head.php";
}
?>
<style>
html,body{background-color:var(--background-color,#0a0a1a) !important;color:var(--text-color,#ffffff) !important;}
main{max-width:1100px;margin:0 auto;padding:18px 20px}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:14px;margin:12px 0}
a{color:#7cc4ff;text-decoration:none}
a:hover{text-decoration:underline}
</style>
</head>
<body>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-body-start.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-body-start.php";
}
?>
<main class="main-content">
<h1 style="margin:0 0 10px 0">Brain</h1>
<div class="card">
    <div style="font-weight:700;margin-bottom:6px">Control</div>
    <div><a href="/control/brain">Open Brain Control UI</a></div>
</div>
</main>
<?php
if (is_file($templatesPath . "/global-ui/includes/complete-body-end.php")) {
    include_once $templatesPath . "/global-ui/includes/complete-body-end.php";
}
?>
</body>
</html>
