<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['mh_auth_user'])) {
    $redirect = '/hub/workbench/status.php';
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$username = (string)($_SESSION['mh_auth_user'] ?? '');
mh_auth_load_user_context($username);
$tenantId = (string)($_SESSION['mh_tenant_id'] ?? ('user:' . $username));
$personaId = (string)($_SESSION['mh_selected_persona'] ?? ($_SESSION['mh_auth_persona'] ?? ('MH-' . $username)));

$tenantSafe = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $tenantId);
$tenantSafe = strtolower(trim((string)$tenantSafe, '._-'));
$personaSafe = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $personaId);
$personaSafe = strtolower(trim((string)$personaSafe, '._-'));

$dataRoot = function_exists('paths_getDataPath') ? paths_getDataPath() : '/data';
$tenantRoot = rtrim($dataRoot, '/') . '/tenants/' . $tenantSafe;
$workspaceRoot = $tenantRoot . '/workspaces/' . $personaSafe . '/default';

@mkdir($tenantRoot, 0700, true);
@mkdir($workspaceRoot, 0700, true);

if (function_exists('cue_autoload')) {
    cue_autoload('models');
}
$models = function_exists('models_get_models') ? models_get_models() : [];
$modelCount = is_array($models) ? count($models) : 0;

$checks = [
    ['id' => 'p1', 'label' => 'Workbench UI', 'ok' => is_file(__DIR__ . '/index.php')],
    ['id' => 'p2', 'label' => 'Context binding', 'ok' => is_file(__DIR__ . '/api/_context.php')],
    ['id' => 'p3', 'label' => 'Data root (/data)', 'ok' => is_dir('/data')],
    ['id' => 'p3b', 'label' => 'Tenant root', 'ok' => is_dir($tenantRoot)],
    ['id' => 'p3c', 'label' => 'Workspace root', 'ok' => is_dir($workspaceRoot)],
    ['id' => 'p4', 'label' => 'Runtime API', 'ok' => is_file(__DIR__ . '/api/runtime.php')],
    ['id' => 'p5', 'label' => 'Model router', 'ok' => is_file(__DIR__ . '/api/models.php')],
    ['id' => 'p6', 'label' => 'Multimodal inbox', 'ok' => is_file(__DIR__ . '/api/inbox.php')],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workbench Status</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body.workbench-status-page main.main-content { background: #0a0a0f; color: #e8e8f0; font-family: Rajdhani, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 28px 16px; }
        .card { background: rgba(10,18,40,0.65); border: 1px solid rgba(0,255,255,0.18); border-radius: 14px; padding: 18px; backdrop-filter: blur(10px); }
        .row { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        .pill { padding: 8px 10px; border-radius: 999px; background: rgba(0,255,255,0.08); border: 1px solid rgba(0,255,255,0.16); color: #b9ffff; font-size: 14px; }
        .ok { color: #7CFFB2; }
        .bad { color: #FF7C9E; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        td { padding: 10px 8px; border-top: 1px solid rgba(255,255,255,0.08); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; }
        a { color: #b9ffff; text-decoration: none; }
    </style>
</head>
<body class="workbench-status-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
<div class="wrap">
    <div class="card">
        <div class="row" style="justify-content: space-between;">
            <div>
                <div style="font-family: Orbitron, Rajdhani, sans-serif; font-size: 18px; margin-bottom: 6px;">Workbench Status</div>
                <div style="color: rgba(232,232,240,0.75);">Tenant + persona scoped readiness checks.</div>
            </div>
            <div class="row">
                <span class="pill"><?php echo htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="pill"><?php echo htmlspecialchars($personaId, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>

        <table>
            <?php foreach ($checks as $c): ?>
                <tr>
                    <td style="width: 180px;" class="mono"><?php echo htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td style="width: 120px; text-align:right;" class="<?php echo $c['ok'] ? 'ok' : 'bad'; ?>">
                        <?php echo $c['ok'] ? 'OK' : 'MISSING'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div style="margin-top: 14px;">
            <div class="mono">data_root=<?php echo htmlspecialchars($dataRoot, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="mono">tenant_root=<?php echo htmlspecialchars($tenantRoot, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="mono">workspace_root=<?php echo htmlspecialchars($workspaceRoot, ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="mono">models_count=<?php echo (int)$modelCount; ?></div>
        </div>

        <div class="row" style="margin-top: 16px;">
            <a class="pill" href="/hub/workbench/?mode=do">Back to Workbench</a>
        </div>
    </div>
</div>
</main>
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
