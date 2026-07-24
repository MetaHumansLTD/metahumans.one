<?php
require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/kripz_gate.php';

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower(trim((string)$_SERVER['HTTP_X_REQUESTED_WITH'])) === 'xmlhttprequest';
mh_kripz_require('registration_policy', $isAjax);

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$_SESSION['current_realm'] = 'hub';

function mh_reg_policy_csrf_get(): string
{
    $k = $_SESSION['mh_reg_policy_csrf'] ?? '';
    if (!is_string($k) || $k === '') {
        $k = bin2hex(random_bytes(16));
        $_SESSION['mh_reg_policy_csrf'] = $k;
    }
    return $k;
}

function mh_reg_policy_csrf_check(string $posted): bool
{
    $k = $_SESSION['mh_reg_policy_csrf'] ?? '';
    return is_string($k) && $k !== '' && hash_equals($k, $posted);
}

$flashMsg = isset($_SESSION['mh_reg_policy_flash_msg']) ? (string)$_SESSION['mh_reg_policy_flash_msg'] : '';
$flashType = isset($_SESSION['mh_reg_policy_flash_type']) ? (string)$_SESSION['mh_reg_policy_flash_type'] : '';
unset($_SESSION['mh_reg_policy_flash_msg'], $_SESSION['mh_reg_policy_flash_type']);

try {
    $pdoBio = database_getConnectionById('biometrics');
    $pdoBio->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (function_exists('mh_registration_ensure_policy_schema')) {
        mh_registration_ensure_policy_schema($pdoBio);
    }
    if (function_exists('mh_registration_seed_default_policy_rules')) {
        mh_registration_seed_default_policy_rules($pdoBio);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection error';
    exit;
}

$scopes = [
    'real_first_name' => 'Real first name',
    'real_last_name' => 'Real last name',
    'display_name' => 'Display name',
    'username' => 'Username',
    'persona_name' => 'Persona name',
];
$ruleTypes = [
    'blocked_word' => 'Blocked word',
    'blocked_contains' => 'Blocked contains',
    'blocked_regex' => 'Blocked regex',
];
$actions = [
    'reject' => 'Reject',
    'review' => 'Require review',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!mh_reg_policy_csrf_check($csrf)) {
        $_SESSION['mh_reg_policy_flash_msg'] = 'Invalid session token. Please retry.';
        $_SESSION['mh_reg_policy_flash_type'] = 'err';
        header('Location: /control/registration-policy.php');
        exit;
    }

    $postAction = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $adminUser = trim((string)($_SESSION['mh_auth_user'] ?? ''));

    try {
        if ($postAction === 'add_rule') {
            $scope = isset($_POST['scope']) ? trim((string)$_POST['scope']) : '';
            $ruleType = isset($_POST['rule_type']) ? trim((string)$_POST['rule_type']) : '';
            $pattern = isset($_POST['pattern']) ? trim((string)$_POST['pattern']) : '';
            $act = isset($_POST['rule_action']) ? trim((string)$_POST['rule_action']) : 'reject';
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if (!isset($scopes[$scope])) throw new RuntimeException('Invalid scope');
            if (!isset($ruleTypes[$ruleType])) throw new RuntimeException('Invalid rule type');
            if ($pattern === '') throw new RuntimeException('Pattern is required');
            if (!isset($actions[$act])) throw new RuntimeException('Invalid action');
            if ($ruleType === 'blocked_regex') {
                $ok = @preg_match($pattern, 'test');
                if ($ok === false) throw new RuntimeException('Invalid regex');
            }

            $stmt = $pdoBio->prepare("INSERT INTO mh_registration_policy_rules (scope, rule_type, pattern, action, enabled, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$scope, $ruleType, $pattern, $act, $enabled, $adminUser !== '' ? $adminUser : null, $adminUser !== '' ? $adminUser : null]);
            $_SESSION['mh_reg_policy_flash_msg'] = 'Rule added.';
            $_SESSION['mh_reg_policy_flash_type'] = 'ok';
        } elseif ($postAction === 'update_rule') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $scope = isset($_POST['scope']) ? trim((string)$_POST['scope']) : '';
            $ruleType = isset($_POST['rule_type']) ? trim((string)$_POST['rule_type']) : '';
            $pattern = isset($_POST['pattern']) ? trim((string)$_POST['pattern']) : '';
            $act = isset($_POST['rule_action']) ? trim((string)$_POST['rule_action']) : 'reject';
            $enabled = isset($_POST['enabled']) ? 1 : 0;

            if ($id < 1) throw new RuntimeException('Invalid rule id');
            if (!isset($scopes[$scope])) throw new RuntimeException('Invalid scope');
            if (!isset($ruleTypes[$ruleType])) throw new RuntimeException('Invalid rule type');
            if ($pattern === '') throw new RuntimeException('Pattern is required');
            if (!isset($actions[$act])) throw new RuntimeException('Invalid action');
            if ($ruleType === 'blocked_regex') {
                $ok = @preg_match($pattern, 'test');
                if ($ok === false) throw new RuntimeException('Invalid regex');
            }

            $stmt = $pdoBio->prepare("UPDATE mh_registration_policy_rules SET scope = ?, rule_type = ?, pattern = ?, action = ?, enabled = ?, updated_by = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$scope, $ruleType, $pattern, $act, $enabled, $adminUser !== '' ? $adminUser : null, $id]);
            $_SESSION['mh_reg_policy_flash_msg'] = 'Rule updated.';
            $_SESSION['mh_reg_policy_flash_type'] = 'ok';
        } elseif ($postAction === 'disable_rule') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id < 1) throw new RuntimeException('Invalid rule id');
            $stmt = $pdoBio->prepare("UPDATE mh_registration_policy_rules SET enabled = 0, updated_by = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$adminUser !== '' ? $adminUser : null, $id]);
            $_SESSION['mh_reg_policy_flash_msg'] = 'Rule disabled.';
            $_SESSION['mh_reg_policy_flash_type'] = 'ok';
        } else {
            $_SESSION['mh_reg_policy_flash_msg'] = 'Invalid action.';
            $_SESSION['mh_reg_policy_flash_type'] = 'err';
        }
    } catch (Throwable $e) {
        $_SESSION['mh_reg_policy_flash_msg'] = $e->getMessage();
        $_SESSION['mh_reg_policy_flash_type'] = 'err';
    }

    header('Location: /control/registration-policy.php');
    exit;
}

$rules = [];
$queue = [];
try {
    $rules = $pdoBio->query("SELECT id, scope, rule_type, pattern, action, enabled, created_by, updated_by, created_at, updated_at FROM mh_registration_policy_rules ORDER BY scope ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $rules = [];
}
try {
    $queue = $pdoBio->query("SELECT id, username, ip_address, device_fingerprint, scope, reason, raw_value, created_at FROM mh_registration_review_queue ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $queue = [];
}

$csrf = mh_reg_policy_csrf_get();
$baseUrl = function_exists('getBaseUrl') ? getBaseUrl() : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Policy</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-rp-shell { max-width: 1100px; margin: 0 auto; padding: 28px 16px 60px; }
        .mh-rp-card { background: rgba(20, 20, 25, 0.92); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 18px; margin-bottom: 18px; }
        .mh-rp-title { color: #00d4ff; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; margin: 0 0 12px; }
        .mh-rp-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .mh-rp-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
        .mh-rp-row-4 { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
        .mh-rp-label { display:block; font-size: 12px; color: rgba(255,255,255,0.75); margin-bottom: 6px; }
        .mh-rp-input, .mh-rp-select { width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color: #fff; outline: none; }
        .mh-rp-input:focus, .mh-rp-select:focus { border-color: rgba(0,212,255,0.65); box-shadow: 0 0 0 3px rgba(0,212,255,0.12); }
        .mh-rp-btn { padding: 10px 14px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.45); background: rgba(0,212,255,0.10); color: #00d4ff; cursor: pointer; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; }
        .mh-rp-btn:hover { background: rgba(0,212,255,0.16); border-color: rgba(0,212,255,0.70); }
        .mh-rp-btn-danger { border-color: rgba(255, 59, 48, 0.55); color: #ff3b30; background: rgba(255, 59, 48, 0.10); }
        .mh-rp-btn-danger:hover { background: rgba(255, 59, 48, 0.16); border-color: rgba(255, 59, 48, 0.75); }
        .mh-rp-table { width: 100%; border-collapse: collapse; }
        .mh-rp-table th, .mh-rp-table td { text-align: left; padding: 10px 10px; border-bottom: 1px solid rgba(255,255,255,0.08); vertical-align: top; }
        .mh-rp-table th { font-size: 12px; color: rgba(255,255,255,0.70); text-transform: uppercase; letter-spacing: 1px; }
        .mh-rp-muted { color: rgba(255,255,255,0.70); font-size: 12px; }
        .mh-rp-flash-ok { border: 1px solid rgba(52,199,89,0.45); background: rgba(52,199,89,0.10); color: #34c759; padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; }
        .mh-rp-flash-err { border: 1px solid rgba(255,59,48,0.45); background: rgba(255,59,48,0.10); color: #ff3b30; padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; }
        @media (max-width: 900px) {
            .mh-rp-row, .mh-rp-row-3, .mh-rp-row-4 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="hub-page">
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
<div class="mh-rp-shell">
    <div class="mh-rp-card">
        <div style="display:flex; align-items:center; justify-content:space-between; gap: 12px; flex-wrap: wrap;">
            <h1 class="mh-rp-title" style="margin:0;">Registration Policy</h1>
            <div class="mh-rp-muted">
                Signed in as <strong><?php echo htmlspecialchars((string)($_SESSION['mh_auth_user'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                · <a href="<?php echo htmlspecialchars($baseUrl . '/hub/index.php', ENT_QUOTES, 'UTF-8'); ?>" style="color:#00d4ff; text-decoration:none;">Hub</a>
            </div>
        </div>
    </div>

    <?php if ($flashMsg !== ''): ?>
        <div class="<?php echo ($flashType === 'ok') ? 'mh-rp-flash-ok' : 'mh-rp-flash-err'; ?>">
            <?php echo htmlspecialchars($flashMsg, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="mh-rp-card">
        <h2 class="mh-rp-title" style="margin-top:0;">Add Rule</h2>
        <form method="POST" action="/control/registration-policy.php">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="add_rule">
            <div class="mh-rp-row-4" style="margin-bottom: 12px;">
                <div>
                    <label class="mh-rp-label">Scope</label>
                    <select class="mh-rp-select" name="scope" required>
                        <?php foreach ($scopes as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mh-rp-label">Rule Type</label>
                    <select class="mh-rp-select" name="rule_type" required>
                        <?php foreach ($ruleTypes as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mh-rp-label">Action</label>
                    <select class="mh-rp-select" name="rule_action" required>
                        <?php foreach ($actions as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; align-items:flex-end; gap: 10px;">
                    <label style="display:flex; gap: 8px; align-items:center; color: rgba(255,255,255,0.85);">
                        <input type="checkbox" name="enabled" checked>
                        Enabled
                    </label>
                    <button type="submit" class="mh-rp-btn">Add</button>
                </div>
            </div>
            <div>
                <label class="mh-rp-label">Pattern</label>
                <input class="mh-rp-input" type="text" name="pattern" placeholder="Example: test | /\\d/ | qwerty" required>
                <div class="mh-rp-muted" style="margin-top:6px;">Regex must include delimiters, e.g. /(.)\\1\\1\\1/i</div>
            </div>
        </form>
    </div>

    <div class="mh-rp-card">
        <h2 class="mh-rp-title" style="margin-top:0;">Rules</h2>
        <div style="overflow:auto;">
            <table class="mh-rp-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Scope</th>
                        <th>Type</th>
                        <th>Pattern</th>
                        <th>Action</th>
                        <th>Enabled</th>
                        <th>Audit</th>
                        <th>Save</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <?php
                        $rid = (int)($r['id'] ?? 0);
                        $scope = (string)($r['scope'] ?? '');
                        $type = (string)($r['rule_type'] ?? '');
                        $pattern = (string)($r['pattern'] ?? '');
                        $act = (string)($r['action'] ?? 'reject');
                        $enabled = (int)($r['enabled'] ?? 0) === 1;
                        $createdBy = (string)($r['created_by'] ?? '');
                        $updatedBy = (string)($r['updated_by'] ?? '');
                        $createdAt = (string)($r['created_at'] ?? '');
                        $updatedAt = (string)($r['updated_at'] ?? '');
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <form method="POST" action="/control/registration-policy.php" style="display:flex; gap: 8px; align-items:flex-start;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="update_rule">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8'); ?>">
                                <select class="mh-rp-select" name="scope" style="min-width: 160px;">
                                    <?php foreach ($scopes as $k => $label): ?>
                                        <option value="<?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($k === $scope) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td>
                                <select class="mh-rp-select" name="rule_type" style="min-width: 170px;">
                                    <?php foreach ($ruleTypes as $k => $label): ?>
                                        <option value="<?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($k === $type) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td style="min-width: 320px;">
                                <input class="mh-rp-input" type="text" name="pattern" value="<?php echo htmlspecialchars($pattern, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td>
                                <select class="mh-rp-select" name="rule_action" style="min-width: 160px;">
                                    <?php foreach ($actions as $k => $label): ?>
                                        <option value="<?php echo htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($k === $act) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td>
                                <label style="display:flex; gap: 8px; align-items:center; color: rgba(255,255,255,0.85);">
                                    <input type="checkbox" name="enabled" <?php echo $enabled ? 'checked' : ''; ?>>
                                    Enabled
                                </label>
                        </td>
                        <td class="mh-rp-muted" style="min-width: 220px;">
                                <div>Created: <?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?> <?php echo $createdBy !== '' ? ('· ' . htmlspecialchars($createdBy, ENT_QUOTES, 'UTF-8')) : ''; ?></div>
                                <div>Updated: <?php echo htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8'); ?> <?php echo $updatedBy !== '' ? ('· ' . htmlspecialchars($updatedBy, ENT_QUOTES, 'UTF-8')) : ''; ?></div>
                        </td>
                        <td style="white-space: nowrap;">
                                <button type="submit" class="mh-rp-btn">Save</button>
                            </form>
                            <form method="POST" action="/control/registration-policy.php" style="margin-top: 8px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="disable_rule">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$rid, ENT_QUOTES, 'UTF-8'); ?>">
                                <button type="submit" class="mh-rp-btn mh-rp-btn-danger">Disable</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!is_array($rules) || count($rules) === 0): ?>
                    <tr><td colspan="8" class="mh-rp-muted">No rules found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mh-rp-card">
        <h2 class="mh-rp-title" style="margin-top:0;">Manual Review Queue (Latest 50)</h2>
        <div style="overflow:auto;">
            <table class="mh-rp-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Created</th>
                        <th>User</th>
                        <th>Scope</th>
                        <th>Reason</th>
                        <th>Value</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($queue as $q): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($q['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="mh-rp-muted"><?php echo htmlspecialchars((string)($q['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($q['username'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($q['scope'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string)($q['reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="mh-rp-muted"><?php echo htmlspecialchars((string)($q['raw_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="mh-rp-muted"><?php echo htmlspecialchars((string)($q['ip_address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!is_array($queue) || count($queue) === 0): ?>
                    <tr><td colspan="7" class="mh-rp-muted">Queue is empty.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
</body>
</html>
