<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
    header('Location: /auth/login.php');
    exit;
}

function mh_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'default';
}

function mh_tenant_id(string $username): string
{
    $t = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($t === '') $t = 'user:' . $username;
    return $t;
}

function mh_apply_tenant(string $tenantId): void
{
    $tenantProvisioning = __DIR__ . '/../../auth/tenant_provisioning.php';
    if ($tenantId !== '' && !function_exists('mh_apply_tenant_context') && is_file($tenantProvisioning)) {
        require_once $tenantProvisioning;
    }
    if ($tenantId !== '' && function_exists('mh_apply_tenant_context')) {
        try { mh_apply_tenant_context($tenantId); } catch (Throwable $e) {}
    }
}

function mh_save_upload(string $field, string $dstPath, int $maxBytes): bool
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return false;
    $f = $_FILES[$field];
    $err = isset($f['error']) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) return false;
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('upload_failed');
    $tmp = isset($f['tmp_name']) ? (string)$f['tmp_name'] : '';
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('upload_failed');
    $size = isset($f['size']) ? (int)$f['size'] : 0;
    if ($size <= 0 || $size > $maxBytes) throw new RuntimeException('upload_too_large');
    $dir = dirname($dstPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('mkdir_failed');
    }
    if (!move_uploaded_file($tmp, $dstPath)) throw new RuntimeException('upload_failed');
    @chmod($dstPath, 0600);
    return true;
}

$username = trim((string)$_SESSION['mh_auth_user']);
$tenantId = mh_tenant_id($username);
$tenantSafe = mh_safe_id($tenantId);
mh_apply_tenant($tenantId);

$personaId = isset($_GET['persona_id']) ? mh_safe_id((string)$_GET['persona_id']) : '';
if ($personaId === '') $personaId = 'default';

$personaRoot = '/data/tenants/' . $tenantSafe . '/personas/' . $personaId;
$avatarPath = $personaRoot . '/assets/images/normalized/avatar.png';
$voiceRefPath = '/data/tenants/' . $tenantSafe . '/voices/' . $personaId . '/reference.wav';

$message = '';
$error = '';

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST') {
    try {
        mh_save_upload('avatar_image', $avatarPath, 10 * 1024 * 1024);
        mh_save_upload('voice_reference', $voiceRefPath, 20 * 1024 * 1024);
        $message = 'Saved.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$avatarUrl = '/hub/genesis/avatar.php?persona=' . rawurlencode($personaId) . '&v=' . time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genesis: Edit Persona</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        body { background: #0a0a0a; color: #00d4ff; font-family: 'Rajdhani', sans-serif; margin: 0; }
        .wrap { display:flex; justify-content:center; padding: 18px; }
        .card { width: 100%; max-width: 760px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 18px; }
        h1 { margin: 0 0 8px; font-weight: 300; letter-spacing: 2px; }
        .sub { color: rgba(255,255,255,0.7); margin: 0 0 14px; }
        .row { display:grid; grid-template-columns: 240px 1fr; gap: 16px; align-items:start; }
        .avatar { width: 240px; aspect-ratio: 1/1; background:#000; border-radius: 12px; border: 1px solid rgba(255,255,255,0.12); overflow:hidden; }
        .avatar img { width:100%; height:100%; object-fit:contain; display:block; }
        .box { background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 14px; }
        label { display:block; color: rgba(255,255,255,0.7); font-size: 13px; margin: 10px 0 6px; }
        input[type="file"] { width: 100%; background: rgba(255,255,255,0.02); border: 1px dashed rgba(255,255,255,0.18); border-radius: 8px; padding: 10px 12px; color: rgba(255,255,255,0.8); box-sizing: border-box; }
        .btn { background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%); border: none; padding: 12px 16px; color: white; font-weight: bold; border-radius: 10px; cursor: pointer; font-size: 14px; text-decoration: none; display: inline-block; }
        .btn.secondary { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); }
        .actions { display:flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .ok { color: rgba(120,255,180,0.9); margin-top: 10px; }
        .err { color: #ff5a7a; margin-top: 10px; }
    </style>
</head>
<body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
        <div class="wrap">
            <div class="card">
                <h1>Edit Persona</h1>
                <p class="sub">persona_id: <?php echo htmlspecialchars($personaId, ENT_QUOTES); ?></p>
                <div class="row">
                    <div class="avatar"><img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES); ?>" alt="Avatar"></div>
                    <div class="box">
                        <form method="POST" enctype="multipart/form-data">
                            <label>Avatar Image (replaces normalized/avatar.png)</label>
                            <input type="file" name="avatar_image" accept="image/png,image/jpeg,image/jpg">
                            <label>Voice Reference (optional, enables manual voice)</label>
                            <input type="file" name="voice_reference" accept="audio/wav,audio/*">
                            <div class="actions">
                                <button class="btn" type="submit">Save</button>
                                <a class="btn secondary" href="/hub/genesis/personas.php">Back</a>
                            </div>
                        </form>
                        <?php if ($message !== ''): ?><div class="ok"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div><?php endif; ?>
                        <?php if ($error !== ''): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>

