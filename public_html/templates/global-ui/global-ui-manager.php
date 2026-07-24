<?php
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once dirname(dirname(__DIR__)) . '/auth/kripz_gate.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$GLOBALS['_GLOBAL_UI_MANAGER_LOADED'] = true;

$u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($u !== '') {
    mh_kripz_refresh_role($u);
}

$userRole = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
$isKripzMaster = ($userRole !== '' && stripos($userRole, 'kripzmaster') !== false);

$component = isset($_GET['component']) ? trim((string)$_GET['component']) : '';
$allowedComponents = [
    'header' => 'header-manager.php',
    'footer' => 'footer-manager.php',
    'hamburger' => 'hamburger-manager.php',
    'widgets' => 'widgets-manager.php',
    'theme' => 'theme-manager.php',
];
if ($component !== '' && !array_key_exists($component, $allowedComponents)) {
    $component = '';
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global UI Manager</title>
    <?php include_once __DIR__ . '/includes/complete-head.php'; ?>
    <?php if (function_exists('includeNoticesWidget')) { includeNoticesWidget(); } ?>
    <style>
        body { margin: 0 !important; }
        .gui-shell { display: flex; min-height: 100vh; background: linear-gradient(135deg, var(--background-color, #0a0a1a) 0%, var(--surface-color, #1a1a2e) 100%); color: var(--text-color, #fff); }
        .gui-side { width: 280px; padding: 18px; border-right: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2); background: rgba(0,0,0,0.25); backdrop-filter: blur(10px); }
        .gui-title { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 18px; font-weight: 700; color: var(--theme-primary, #00ffff); margin: 0 0 12px 0; }
        .gui-sub { font-size: 13px; color: rgba(255,255,255,0.75); margin: 0 0 16px 0; }
        .gui-nav a { display: block; padding: 10px 12px; margin: 8px 0; border-radius: 10px; text-decoration: none; color: var(--theme-primary, #00ffff); border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.2); background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.06); }
        .gui-nav a.active { background: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.14); border-color: rgba(var(--theme-primary-rgb, 0, 255, 255), 0.35); }
        .gui-main { flex: 1; padding: 22px; }
        .gui-card { background: rgba(255,255,255,0.04); border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.18); border-radius: 16px; padding: 18px; }
        .gui-topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .gui-badge { font-size: 12px; padding: 6px 10px; border-radius: 999px; border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.25); color: var(--theme-primary, #00ffff); background: rgba(0,0,0,0.25); }
        .content { margin-top: 14px; }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/includes/complete-body-start.php'; ?>

<div class="gui-shell">
    <aside class="gui-side">
        <div class="gui-title">Global UI</div>
        <div class="gui-sub">Manage site-wide UI components</div>
        <div class="gui-nav">
            <a href="global-ui-manager.php" class="<?php echo $component === '' ? 'active' : ''; ?>">Overview</a>
            <a href="global-ui-manager.php?component=header" class="<?php echo $component === 'header' ? 'active' : ''; ?>">Header</a>
            <a href="global-ui-manager.php?component=hamburger" class="<?php echo $component === 'hamburger' ? 'active' : ''; ?>">Hamburger</a>
            <a href="global-ui-manager.php?component=footer" class="<?php echo $component === 'footer' ? 'active' : ''; ?>">Footer</a>
            <a href="global-ui-manager.php?component=widgets" class="<?php echo $component === 'widgets' ? 'active' : ''; ?>">Widgets</a>
            <a href="global-ui-manager.php?component=theme" class="<?php echo $component === 'theme' ? 'active' : ''; ?>">Theme</a>
        </div>
    </aside>

    <main class="gui-main">
        <div class="gui-topbar">
            <div class="gui-title" style="margin:0"><?php echo $component !== '' ? ucfirst($component) . ' Manager' : 'Overview'; ?></div>
            <div class="gui-badge"><?php echo $isKripzMaster ? 'KripzMasters' : 'User'; ?></div>
        </div>

        <div class="gui-card">
            <div class="content">
                <?php
                if ($component === '') {
                    echo '<div style="font-size:14px;line-height:1.6;color:rgba(255,255,255,0.85)">';
                    echo '<div style="margin-bottom:10px">Recommended integration path:</div>';
                    echo '<div style="font-family:monospace;background:rgba(0,0,0,0.35);padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,0.1)">';
                    echo htmlspecialchars("<!-- <head> -->\ninclude_once getTemplatesPath() . '/global-ui/includes/complete-head.php';\n\n<!-- start of <body> -->\ninclude_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php';\n\n<!-- before </body> -->\ninclude_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php';");
                    echo '</div>';
                    echo '</div>';
                } else {
                    if (!$isKripzMaster && $component !== 'theme') {
                        echo '<div style="padding:14px;border:1px solid rgba(255,0,0,0.35);background:rgba(255,0,0,0.08);border-radius:12px">';
                        echo 'Access denied: KripzMasters only';
                        echo '</div>';
                    } else {
                        $target = '/templates/global-ui/' . $allowedComponents[$component];
                        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">';
                        echo '<a href="' . htmlspecialchars($target) . '" style="display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;text-decoration:none;color:var(--theme-primary,#00ffff);border:1px solid rgba(var(--theme-primary-rgb,0,255,255),0.25);background:rgba(var(--theme-primary-rgb,0,255,255),0.08)">Open ' . htmlspecialchars(ucfirst($component)) . ' Manager</a>';
                        echo '<a href="' . htmlspecialchars($target) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;text-decoration:none;color:rgba(255,255,255,0.85);border:1px solid rgba(255,255,255,0.15);background:rgba(0,0,0,0.25)">Open in new tab</a>';
                        echo '</div>';
                        echo '<div style="margin-top:14px;color:rgba(255,255,255,0.8);font-size:14px;line-height:1.6">';
                        echo 'This manager runs as a standalone page to avoid script/style collisions and browser syntax errors.';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
    </main>
</div>

<?php include_once __DIR__ . '/includes/complete-body-end.php'; ?>
</body>
</html>
