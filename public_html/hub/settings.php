<?php
/**
 * Meta Humans Hub - Settings
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/auth_classes.php';
require_once __DIR__ . '/../auth/persona_registry.php';
require_once __DIR__ . '/../gear/grid/customers.php';
require_once __DIR__ . '/../gear/grid/whm_mail.php';

// Force load theme module
if (function_exists('cue_autoload')) {
    cue_autoload('theme');
}

// Start Session
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

$message = '';
if (isset($_SESSION['hub_settings_flash_message']) && is_string($_SESSION['hub_settings_flash_message'])) {
    $message = $_SESSION['hub_settings_flash_message'];
    unset($_SESSION['hub_settings_flash_message']);
} elseif (isset($_GET['device_added']) && (string)$_GET['device_added'] === '1') {
    $message = 'This device was added successfully.';
}

// Auth Check
if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

$scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
if ($scriptName === '/hub/settings.php') {
    header('Location: /auth/settings.php', true, 302);
    exit;
}

// Load theme configuration
$themeConfig = theme_loadConfiguration();
$username = $_SESSION['mh_auth_user'];

$user = [];
$gridEmail = '';
$gridEmailStatus = 'not_configured';

try {
    if (function_exists('mh_auth_user_store_pdo')) {
        $pdo = mh_auth_user_store_pdo();
    } else {
        throw new RuntimeException('auth_user_store_unavailable');
    }
        
        // Handle Save
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_identity') {
            $oldUsername = $username;
            $newUsername = trim($_POST['username'] ?? '');
            $newPersona = trim($_POST['persona_name'] ?? '');
            $realFirst = trim((string)($_POST['real_first_name'] ?? ''));
            $realLast = trim((string)($_POST['real_last_name'] ?? ''));
            $newName = trim($realFirst . ' ' . $realLast);
            
            if ($newUsername === '') {
                 $message = "Username cannot be empty.";
            } else {
                try {
                    if (function_exists('mh_validate_real_first_and_surname_strict')) {
                        mh_validate_real_first_and_surname_strict($realFirst, $realLast);
                    }
                    // Start transaction
                    $pdo->beginTransaction();
                    
                    // Check if new username is taken (if changed)
                    if ($newUsername !== $username) {
                        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                        $check->execute([$newUsername]);
                        if ($check->fetch()) {
                            throw new Exception("Username '$newUsername' is already taken.");
                        }
                    }

                    if ($newPersona !== '') {
                        $pdoPersona = mh_persona_registry_pdo();
                        $checkPersona = $pdoPersona->prepare("SELECT owner_username FROM mh_personas WHERE persona_name = ? AND owner_username <> ? LIMIT 1");
                        $checkPersona->execute([$newPersona, $username]);
                        if ($checkPersona->fetch()) {
                            throw new Exception("Meta Human Persona name '{$newPersona}' is already taken.");
                        }
                    }

                    $stmtCols = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'");
                    if ($stmtCols->rowCount() === 0) {
                        $pdo->exec("ALTER TABLE users ADD COLUMN name VARCHAR(255) DEFAULT NULL AFTER username");
                    }
                    if (function_exists('mh_ensure_user_real_name_schema')) {
                        mh_ensure_user_real_name_schema($pdo);
                    }

                    $oldPersona = '';
                    $oldTenantId = '';
                    try {
                        $stmt = $pdo->prepare("SELECT persona_name, tenant_id FROM users WHERE username = ? LIMIT 1");
                        $stmt->execute([$username]);
                        $r = $stmt->fetch(PDO::FETCH_ASSOC);
                        $oldPersona = is_array($r) && isset($r['persona_name']) ? trim((string)$r['persona_name']) : '';
                        $oldTenantId = is_array($r) && isset($r['tenant_id']) ? trim((string)$r['tenant_id']) : '';
                    } catch (Throwable $e) {}
                    if ($oldTenantId === '') $oldTenantId = 'user:' . $username;
                    $newTenantId = 'user:' . $newUsername;

                    $upd = $pdo->prepare("UPDATE users SET username = ?, tenant_id = ?, persona_name = ?, name = ?, real_first_name = ?, real_last_name = ? WHERE username = ?");
                    $upd->execute([$newUsername, $newTenantId, $newPersona, $newName, $realFirst, $realLast, $username]);
                    
                    // Update Personas table if username changed
                    if ($newUsername !== $username) {
                        try {
                            $pdoPersona = mh_persona_registry_pdo();
                            mh_persona_registry_update_owner($pdoPersona, $username, $newUsername);
                        } catch (Throwable) {}
                    }

                    if ($newUsername !== $username) {
                        try {
                            $cols = $pdo->query("SHOW COLUMNS FROM webauthn_credentials");
                            if ($cols) {
                                $rows = $cols->fetchAll(PDO::FETCH_ASSOC);
                                $fields = [];
                                foreach ($rows as $rr) {
                                    $f = isset($rr['Field']) ? (string)$rr['Field'] : '';
                                    if ($f !== '') $fields[$f] = true;
                                }
                                if (isset($fields['user_id'])) {
                                    $pdo->prepare("UPDATE webauthn_credentials SET user_id = ? WHERE user_id = ?")->execute([(string)$newUsername, (string)$username]);
                                } elseif (isset($fields['username'])) {
                                    $pdo->prepare("UPDATE webauthn_credentials SET username = ? WHERE username = ?")->execute([(string)$newUsername, (string)$username]);
                                }
                            }
                        } catch (Throwable $e) {}
                    }
                    if ($newUsername !== $username) {
                        try {
                            require_once __DIR__ . '/../auth/auth_classes.php';
                            $auth = new MetaPasskeyAuth();
                            $auth->migrateUserCredentials($username, $newUsername);
                        } catch (Throwable $e) {}
                    }

                    if ($newPersona !== '' && $oldPersona !== '' && $newPersona !== $oldPersona) {
                        try {
                            $pdoPersona = mh_persona_registry_pdo();
                            mh_persona_registry_release($pdoPersona, $newUsername, $oldPersona);
                            if (!mh_persona_registry_claim($pdoPersona, $newUsername, $newPersona)) {
                                throw new Exception("Meta Human Persona name '{$newPersona}' is already taken.");
                            }
                        } catch (Throwable $e) {}
                    }
                    
                    $pdo->commit();
                    
                    // Update Session
                    $_SESSION['mh_auth_user'] = $newUsername;
                    $_SESSION['mh_auth_persona'] = $newPersona;
                    $_SESSION['mh_auth_display'] = $newPersona !== '' ? $newPersona : ($newName !== '' ? $newName : $newUsername);
                    $_SESSION['mh_user_real_first_name'] = $realFirst;
                    $_SESSION['mh_user_real_last_name'] = $realLast;
                    if ($newUsername !== $oldUsername) {
                        try { mh_tokenomics_migrate_username((string)$oldUsername, (string)$newUsername); } catch (Throwable $e) {}
                        try { mh_tenant_context_move((string)$oldTenantId, (string)$newTenantId); } catch (Throwable $e) {}
                        try { mh_tenant_storage_move((string)$oldTenantId, (string)$newTenantId); } catch (Throwable $e) {}
                    }

                    $username = $newUsername; // Update local variable for display

                    if (function_exists('mh_auth_load_user_context')) {
                        mh_auth_load_user_context(
                            $newUsername,
                            $_SESSION['mh_auth_groups'] ?? null,
                            null
                        );
                    }
                    
                    $_SESSION['hub_settings_flash_message'] = "Identity settings updated successfully.";
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/hub/settings.php', '?'));
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = "Update Failed: " . $e->getMessage();
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_pin') {
            $pin = (string)($_POST['pin'] ?? '');
            $confirmPin = (string)($_POST['confirm_pin'] ?? '');

            if ($pin === '' || $confirmPin === '') {
                $message = 'PIN is required.';
            } elseif (!preg_match('/^\d{5,}$/', $pin)) {
                $message = 'PIN must be at least 5 digits.';
            } elseif ($pin !== $confirmPin) {
                $message = 'PIN confirmation does not match.';
            } else {
                try {
                    $pinBackup = new MetaPinBackup();
                    $pinBackup->setPinForUser((string)$username, (string)$pin);
                    try {
                        $stmtCols = $pdo->query("SHOW COLUMNS FROM users LIKE 'pin'");
                        if ($stmtCols && $stmtCols->rowCount() > 0) {
                            $pdo->prepare("UPDATE users SET pin = NULL WHERE username = ?")->execute([(string)$username]);
                        }
                    } catch (Throwable $e) {
                    }
                    $_SESSION['hub_settings_flash_message'] = 'PIN updated successfully.';
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/hub/settings.php', '?'));
                    exit;
                } catch (Exception $e) {
                    $message = 'PIN update failed: ' . $e->getMessage();
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_company') {
            $companyName = trim((string)($_POST['company_name'] ?? ''));
            $companyStreet = trim((string)($_POST['company_street_address'] ?? ''));
            $companyCity = trim((string)($_POST['company_city'] ?? ''));
            $companyPostal = trim((string)($_POST['company_postal_code'] ?? ''));
            $companyReg = trim((string)($_POST['company_registration_number'] ?? ''));
            $confirmRepresentative = isset($_POST['confirm_representative']) ? 1 : 0;
            $confirmClicked = isset($_POST['confirm_action']) && $_POST['confirm_action'] === 'confirm_representative';

            if ($confirmClicked && $confirmRepresentative !== 1) {
                $message = 'Please confirm you are an approved representative before submitting.';
            } else {
                try {
                    $columns = [
                        'company_name' => "ALTER TABLE users ADD COLUMN company_name VARCHAR(255) DEFAULT NULL",
                        'company_street_address' => "ALTER TABLE users ADD COLUMN company_street_address VARCHAR(255) DEFAULT NULL",
                        'company_city' => "ALTER TABLE users ADD COLUMN company_city VARCHAR(255) DEFAULT NULL",
                        'company_postal_code' => "ALTER TABLE users ADD COLUMN company_postal_code VARCHAR(64) DEFAULT NULL",
                        'company_registration_number' => "ALTER TABLE users ADD COLUMN company_registration_number VARCHAR(128) DEFAULT NULL",
                        'lora_id' => "ALTER TABLE users ADD COLUMN lora_id VARCHAR(64) DEFAULT NULL",
                        'represent' => "ALTER TABLE users ADD COLUMN represent TINYINT DEFAULT 0",
                    ];
                    foreach ($columns as $col => $ddl) {
                        try {
                            $stmtCols = $pdo->query("SHOW COLUMNS FROM users LIKE " . $pdo->quote($col));
                            if ($stmtCols && $stmtCols->rowCount() === 0) {
                                $pdo->exec($ddl);
                            }
                        } catch (Throwable $e) {}
                    }
                    try {
                        $idx = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'uniq_lora_id'");
                        if ($idx && $idx->rowCount() === 0) {
                            $pdo->exec("CREATE UNIQUE INDEX uniq_lora_id ON users(lora_id)");
                        }
                    } catch (Throwable $e) {}

                    $stmt = $pdo->prepare("SELECT lora_id FROM users WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $loraId = is_array($row) && isset($row['lora_id']) ? trim((string)$row['lora_id']) : '';

                    if ($loraId === '' && $companyName !== '' && $confirmRepresentative !== 1) {
                        $message = 'Representative confirmation is required to create a LoRA_id.';
                        throw new Exception($message);
                    }

                    if ($loraId === '' && $companyName !== '') {
                        $base = preg_replace('/[^A-Za-z0-9]+/', '', $companyName) ?: '';
                        $prefix = substr((string)$base, 0, 4);
                        if ($prefix === '') {
                            $prefix = 'COMP';
                        }
                        $prefix = ucfirst(strtolower($prefix));
                        $tries = 0;
                        while ($tries < 50) {
                            $tries++;
                            $num = random_int(0, 99999);
                            $candidate = $prefix . '-' . str_pad((string)$num, 5, '0', STR_PAD_LEFT);
                            $check = $pdo->prepare("SELECT 1 FROM users WHERE lora_id = ? LIMIT 1");
                            $check->execute([$candidate]);
                            if (!$check->fetchColumn()) {
                                $loraId = $candidate;
                                break;
                            }
                        }
                    }

                    $representToStore = null;
                    if ($confirmClicked || ($loraId !== '' && $confirmRepresentative === 1)) {
                        $representToStore = 1;
                    }

                    $upd = $pdo->prepare("UPDATE users
                        SET company_name = ?,
                            company_street_address = ?,
                            company_city = ?,
                            company_postal_code = ?,
                            company_registration_number = ?,
                            lora_id = COALESCE(NULLIF(lora_id, ''), ?),
                            represent = CASE WHEN ? IS NULL THEN represent ELSE ? END
                        WHERE username = ?");
                    $upd->execute([
                        $companyName !== '' ? $companyName : null,
                        $companyStreet !== '' ? $companyStreet : null,
                        $companyCity !== '' ? $companyCity : null,
                        $companyPostal !== '' ? $companyPostal : null,
                        $companyReg !== '' ? $companyReg : null,
                        $loraId !== '' ? $loraId : null,
                        $representToStore,
                        $representToStore,
                        $username,
                    ]);

                    $_SESSION['hub_settings_flash_message'] = $confirmClicked ? 'Representative confirmation saved.' : 'Company profile updated.';
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '/hub/settings.php', '?'));
                    exit;
                } catch (Throwable $e) {
                    if ($message === '') {
                        $message = 'Company update failed: ' . $e->getMessage();
                    }
                }
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            // Should not happen if logged in
            $user = [
                'id' => 'N/A',
                'username' => $username,
                'persona_name' => '',
                'device_id' => '',
                'tenant_id' => ''
            ];
        }
        if (function_exists('mh_get_token_balance')) {
            $bal = mh_get_token_balance($username);
            if (is_int($bal)) {
                $_SESSION['tokens'] = $bal;
                $user['tokens'] = $bal;
            }
        }
        $gridTenantId = trim((string)($user['tenant_id'] ?? ''));
        if ($gridTenantId === '') {
            $gridTenantId = 'user:' . $username;
        }
        if (function_exists('mh_grid_internal_email_otp_address_for_tenant')) {
            try {
                $derivedGridEmail = (string)mh_grid_internal_email_otp_address_for_tenant($gridTenantId);
                if ($derivedGridEmail !== '') {
                    $gridCustomer = function_exists('mh_grid_customer_get_by_tenant')
                        ? mh_grid_customer_get_by_tenant($pdo, $gridTenantId)
                        : null;
                    $gridMailboxCfg = mh_grid_whm_read_cfg('onemeta');
                    $gridMailboxLookup = mh_grid_whm_mailbox_lookup($gridMailboxCfg, $derivedGridEmail);
                    if (($gridMailboxLookup['ok'] ?? false) !== true) {
                        // Retry once in case the mailbox lookup hit a transient cPanel/API failure.
                        $gridMailboxLookup = mh_grid_whm_mailbox_lookup($gridMailboxCfg, $derivedGridEmail);
                    }
                    if (($gridMailboxLookup['ok'] ?? false) === true) {
                        if (($gridMailboxLookup['exists'] ?? false) === true) {
                            $gridEmail = $derivedGridEmail;
                            $gridEmailStatus = 'created';
                        } else {
                            $gridEmailStatus = 'not_created';
                        }
                    } else {
                        if (is_array($gridCustomer)) {
                            $gridEmail = $derivedGridEmail;
                            $gridEmailStatus = 'lookup_failed_known_customer';
                        } else {
                            $gridEmailStatus = 'lookup_failed';
                        }
                    }
                }
            } catch (Throwable $e) {
                $gridEmail = '';
                $gridEmailStatus = 'lookup_failed';
            }
        }
    } catch (Exception $e) {
        $message = "Database Error: " . $e->getMessage();
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Meta Humans Hub</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        :root {
            --primary: #00d4ff;
            --dark-bg: #0a0a0a;
            --glass-bg: rgba(255, 255, 255, 0.03);
            --border: rgba(255, 255, 255, 0.1);
            --text-main: #ffffff;
            --text-muted: #a1a1aa;
        }

        body.hub-settings main.main-content * {
            box-sizing: border-box;
        }
        body.hub-settings main.main-content {
            color: var(--primary);
            font-family: var(--font-primary, 'Rajdhani', sans-serif);
        }

        .layout-container {
            display: flex;
            flex-direction: column;
            flex: 1;
            width: 100%;
            /* Removed explicit min-height to allow flex-grow to handle footer positioning */
        }

        .hub-content { 
            flex: 1; 
            padding: 40px; 
            margin: 0 auto;
            max-width: 1200px;
            width: 100%;
            background: transparent !important;
        }

        h1 { 
            font-family: 'Orbitron', sans-serif;
            font-weight: 700; 
            letter-spacing: 2px;
            font-size: 2.5rem;
            margin-bottom: 30px;
            color: var(--primary);
        }

        .settings-card {
            background: rgba(20, 20, 25, 0.6);
            backdrop-filter: blur(12px);
            padding: 30px; 
            border-radius: 16px; 
            border: 1px solid var(--border);
            margin-bottom: 20px;
            max-width: 800px;
        }

        .settings-card h2 {
            font-family: 'Orbitron', sans-serif;
            margin-top: 0;
            color: var(--primary);
            border-bottom: 1px solid var(--border);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-weight: 500;
        }

        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px;
            background: rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: #fff;
            font-family: 'Rajdhani', sans-serif;
            font-size: 1rem;
        }

        input:focus {
            border-color: var(--primary);
            outline: none;
        }

        button {
            background: var(--primary);
            color: #000;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-family: 'Orbitron', sans-serif;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 15px rgba(0, 212, 255, 0.4);
        }

        .mh-table { width: 100%; border-collapse: collapse; }
        .mh-table th, .mh-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid rgba(0, 212, 255, 0.15); font-size: 0.95rem; color: rgba(255,255,255,0.9); }
        .mh-table th { color: var(--primary); font-weight: 700; }
        .mh-small { font-size: 0.85rem; color: var(--text-muted); }
        .mh-inline { display:flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .mh-inline select, .mh-inline input[type="text"] { width: auto; min-width: 160px; }
        .mh-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: none; align-items: center; justify-content: center; padding: 18px; z-index: 9999; }
        .mh-modal { width: 100%; max-width: 740px; background: rgba(20, 20, 25, 0.95); border: 1px solid rgba(0, 212, 255, 0.25); border-radius: 16px; padding: 18px; }
        .mh-modal h3 { margin: 0 0 12px 0; color: var(--primary); font-family: 'Orbitron', sans-serif; }
        .mh-modal .mh-actions { display:flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; margin-top: 12px; }
        .mh-btn-secondary { background: rgba(255,255,255,0.06); color: #fff; border: 1px solid rgba(0, 212, 255, 0.25); }
        .mh-btn-danger { background: rgba(239, 68, 68, 0.85); color: #fff; }
        
        /* Footer Adjustment */
        footer, .cue-global-footer {
            border-top: 1px solid var(--border);
            background: var(--dark-bg);
        }
    </style>
</head>
<body class="hub-settings">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
    <div class="layout-container">
        <div class="hub-content">
            <h1>SETTINGS</h1>

            <div class="settings-card">
                <h2>Account Information</h2>
                <?php if (!empty($message)): ?>
                    <div style="background: rgba(0, 212, 255, 0.1); border: 1px solid var(--primary); color: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>
                <div id="identityClientMessage" style="display:none; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.7); color: #fff; padding: 15px; border-radius: 8px; margin-bottom: 20px;"></div>
                <form method="post" id="identityForm">
                    <input type="hidden" name="action" value="update_identity">
                    <div class="form-group">
                        <label>User ID (Internal ID)</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['id'] ?? 'N/A'); ?>" readonly style="opacity: 0.7; cursor: not-allowed;">
                    </div>
                    <div class="form-group">
                        <label>Username (user_id)</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" placeholder="Enter Username">
                    </div>
                    <div class="form-group">
                        <label>Real Name</label>
                        <input type="text" name="real_first_name" value="<?php echo htmlspecialchars($user['real_first_name'] ?? ''); ?>" placeholder="Exactly as on your ID/Passport/Drivers License">
                    </div>
                    <div class="form-group">
                        <label>Real Surname</label>
                        <input type="text" name="real_last_name" value="<?php echo htmlspecialchars($user['real_last_name'] ?? ''); ?>" placeholder="Exactly as on your ID/Passport/Drivers License">
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">Exactly as on your ID/Passport/Drivers License as this will become important for payout requests.</div>
                    </div>
                    <div class="form-group">
                        <label>Give a name to your Meta Human Persona</label>
                        <input type="text" name="persona_name" value="<?php echo htmlspecialchars($user['persona_name'] ?? ''); ?>" placeholder="Give a name to your Meta Human Persona">
                    </div>
                    <div class="form-group">
                        <label>Device ID (device_id)</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['device_id'] ?? ''); ?>" readonly style="opacity: 0.7; cursor: not-allowed;">
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">Unique fingerprint for this device. Auto-generated and locked.</div>
                    </div>
                    <div class="form-group">
                        <label>Tenant ID (tenant_id)</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['tenant_id'] ?? ''); ?>" readonly style="opacity: 0.7; cursor: not-allowed;">
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">Identity space isolation identifier (e.g. user:123). Locked for security.</div>
                    </div>
                    <button type="submit">Update Identity Settings</button>
                </form>
            </div>

            <div class="settings-card">
                <h2>Grid Email</h2>
                <div class="form-group">
                    <label>Internal Grid EMAIL_OTP mailbox</label>
                    <input type="email" value="<?php echo htmlspecialchars($gridEmail); ?>" readonly style="opacity: 0.7; cursor: not-allowed;" placeholder="Not created yet">
                    <?php if ($gridEmailStatus === 'created'): ?>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">Created internal mailbox used for Grid EMAIL_OTP fallback and recovery reference. No direct password reset or user mailbox access is provided here.</div>
                    <?php elseif ($gridEmailStatus === 'lookup_failed_known_customer'): ?>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">The mailbox reference is shown from the tenant's Grid mapping, but the live mailbox lookup is temporarily unavailable.</div>
                    <?php elseif ($gridEmailStatus === 'not_created'): ?>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">The tenant-derived Grid mailbox has not been created yet.</div>
                    <?php else: ?>
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">Grid mailbox status is currently unavailable.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="settings-card">
                <h2>PIN</h2>
                <form method="post">
                    <input type="hidden" name="action" value="update_pin">
                    <div class="form-group">
                        <label>New PIN (min 5 digits)</label>
                        <input type="password" name="pin" inputmode="numeric" pattern="[0-9]{5,}" minlength="5" placeholder="At least 5 digits" required>
                    </div>
                    <div class="form-group">
                        <label>Confirm PIN</label>
                        <input type="password" name="confirm_pin" inputmode="numeric" pattern="[0-9]{5,}" minlength="5" placeholder="At least 5 digits" required>
                    </div>
                    <button type="submit">Update PIN</button>
                </form>
            </div>

            <div class="settings-card">
                <h2>Devices</h2>
                <div class="mh-small" style="margin-bottom: 12px;">Register another passkey on this device for your current account. This uses your existing PIN to confirm it is you.</div>
                <a class="mh-btn-secondary" style="display:inline-block; text-decoration:none; padding: 10px 14px; border-radius: 10px;" href="/auth/register.php?add_device=1">Add This Device Now</a>
            </div>

            <div class="settings-card">
                <h2>Company</h2>
                <form method="post">
                    <input type="hidden" name="action" value="update_company">
                    <div class="form-group">
                        <label>Company Name</label>
                        <input type="text" name="company_name" value="<?php echo htmlspecialchars($user['company_name'] ?? ''); ?>" placeholder="Enter Company Name">
                    </div>
                    <div class="form-group">
                        <label>Company Street Address</label>
                        <input type="text" name="company_street_address" value="<?php echo htmlspecialchars($user['company_street_address'] ?? ''); ?>" placeholder="Enter Street Address">
                    </div>
                    <div class="form-group">
                        <label>Company City</label>
                        <input type="text" name="company_city" value="<?php echo htmlspecialchars($user['company_city'] ?? ''); ?>" placeholder="Enter City">
                    </div>
                    <div class="form-group">
                        <label>Company Postal Code</label>
                        <input type="text" name="company_postal_code" value="<?php echo htmlspecialchars($user['company_postal_code'] ?? ''); ?>" placeholder="Enter Postal Code">
                    </div>
                    <div class="form-group">
                        <label>Company Registration Number</label>
                        <input type="text" name="company_registration_number" value="<?php echo htmlspecialchars($user['company_registration_number'] ?? ''); ?>" placeholder="Enter Registration Number">
                    </div>
                    <div class="form-group">
                        <label>LoRA_id</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['lora_id'] ?? ''); ?>" readonly style="opacity: 0.7; cursor: not-allowed;">
                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 5px;">Auto-generated and locked.</div>
                    </div>
                    <button type="submit">Update Company Profile</button>

                    <div style="margin-top: 18px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.08);">
                        <label style="display:flex; gap:10px; align-items:flex-start;">
                            <input type="checkbox" name="confirm_representative" value="1" <?php echo (!empty($user['represent']) ? 'checked' : ''); ?> style="width:auto; margin:3px 0 0 0;">
                            <span style="color: var(--text-muted);">
                                I am an approved representative of the company listed in my profile and can proof the appointment on request.
                            </span>
                        </label>
                        <button type="submit" name="confirm_action" value="confirm_representative" style="margin-top: 12px;">Confirm Representative</button>
                    </div>
                </form>
            </div>

            <?php if (false): ?>
            <div class="settings-card">
                <h2>BENEFACTORS</h2>
                <div class="mh-small" style="margin-bottom: 12px;">Appoint a benefactor to receive specific asset types per your allocation rules.</div>

                <div style="margin-bottom: 18px;">
                    <div style="font-family:'Orbitron',sans-serif; color: var(--primary); margin-bottom: 10px;">Your Benefactors</div>
                    <?php if (!empty($benefactorsOwned)): ?>
                        <table class="mh-table">
                            <thead>
                                <tr>
                                    <th>Benefactor</th>
                                    <th>Rules</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                                $rulesByUser = [];
                                foreach (($benefactorRulesOwned ?? []) as $rr) {
                                    $bu = isset($rr['benefactor_username']) ? (string)$rr['benefactor_username'] : '';
                                    $at = isset($rr['asset_type']) ? (string)$rr['asset_type'] : '';
                                    if ($bu !== '' && $at !== '') {
                                        $rulesByUser[$bu][$at] = $rr;
                                    }
                                }
                                $assetsForRules = $benefactorAssets ?? [];
                            ?>
                            <?php foreach ($benefactorsOwned as $b): ?>
                                <?php $bu = (string)($b['benefactor_username'] ?? ''); ?>
                                <?php $bst = strtoupper((string)($b['status'] ?? '')); ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string)($b['benefactor_name'] ?? ''), ENT_QUOTES); ?></div>
                                        <div class="mh-small"><?php echo htmlspecialchars($bu, ENT_QUOTES); ?></div>
                                        <?php if ($bst !== '' && $bst !== 'ACTIVE'): ?>
                                            <div class="mh-small">Status: <?php echo htmlspecialchars($bst, ENT_QUOTES); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($assetsForRules)): ?>
                                            <?php foreach ($assetsForRules as $a): ?>
                                                <?php
                                                    $at = (string)($a['type'] ?? '');
                                                    $label = (string)($a['label'] ?? $at);
                                                    $r = $rulesByUser[$bu][$at] ?? null;
                                                    $mode = is_array($r) ? strtolower((string)($r['mode'] ?? 'equal')) : 'equal';
                                                    $val = is_array($r) && isset($r['value_num']) ? (string)$r['value_num'] : '';
                                                ?>
                                                <form method="post" class="mh-inline" style="margin-bottom: 8px;">
                                                    <input type="hidden" name="action" value="benefactor_rule_save">
                                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                                    <input type="hidden" name="benefactor_username" value="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>">
                                                    <input type="hidden" name="asset_type" value="<?php echo htmlspecialchars($at, ENT_QUOTES); ?>">
                                                    <div class="mh-small" style="min-width: 200px;"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></div>
                                                    <select name="mode">
                                                        <option value="equal"<?php echo $mode === 'equal' ? ' selected' : ''; ?>>Equal split</option>
                                                        <option value="percent"<?php echo $mode === 'percent' ? ' selected' : ''; ?>>Percent</option>
                                                        <option value="all"<?php echo $mode === 'all' ? ' selected' : ''; ?>>All (split)</option>
                                                    </select>
                                                    <input type="text" name="value_num" placeholder="%" value="<?php echo htmlspecialchars($val, ENT_QUOTES); ?>" style="min-width: 90px;">
                                                    <button type="submit" class="mh-btn-secondary" style="padding: 10px 14px;">Save</button>
                                                </form>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="mh-small">No assets detected to allocate.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post">
                                            <input type="hidden" name="action" value="benefactor_delete">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                            <input type="hidden" name="benefactor_username" value="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>">
                                            <button type="submit" class="mh-btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="mh-small">No benefactors assigned.</div>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom: 18px;">
                    <div style="font-family:'Orbitron',sans-serif; color: var(--primary); margin-bottom: 10px;">Add Benefactor</div>
                    <form method="post">
                        <input type="hidden" name="action" value="benefactor_add">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                        <div class="form-group">
                            <label>Real Name and Surname</label>
                            <input type="text" name="benefactor_name" placeholder="Real Name and Surname">
                        </div>
                        <div class="form-group">
                            <label>Username (must match the name)</label>
                            <input type="text" name="benefactor_username" placeholder="Username">
                            <div class="mh-small" style="margin-top: 6px;">Must exist on platform and have at least <?php echo (int)mh_benefactors_min_tokens_required(); ?> MTK.</div>
                        </div>
                        <button type="submit">Add Benefactor</button>
                    </form>
                </div>

                <div>
                    <div style="font-family:'Orbitron',sans-serif; color: var(--primary); margin-bottom: 10px;">Your Benefactor Appointments</div>
                    <div class="mh-small" style="margin-bottom: 10px;">Appointments are initiated by owners. You must accept the appointment before you can claim.</div>

                    <?php
                        $pendingAppointments = [];
                        $activeAppointments = [];
                        foreach (($myBenefactorOwners ?? []) as $o) {
                            $st = strtolower(trim((string)($o['status'] ?? '')));
                            if ($st === 'pending') $pendingAppointments[] = $o;
                            if ($st === 'active') $activeAppointments[] = $o;
                        }
                    ?>

                    <?php if (!empty($pendingAppointments)): ?>
                        <div class="mh-small" style="margin-bottom: 8px;">Pending appointment requests</div>
                        <table class="mh-table" style="margin-bottom: 14px;">
                            <thead>
                                <tr>
                                    <th>Owner</th>
                                    <th>Requested</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pendingAppointments as $o): ?>
                                <?php $ou = (string)($o['owner_username'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string)($o['owner_name'] ?? ''), ENT_QUOTES); ?></div>
                                        <div class="mh-small"><?php echo htmlspecialchars($ou, ENT_QUOTES); ?></div>
                                    </td>
                                    <td class="mh-small"><?php echo htmlspecialchars((string)($o['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                    <td>
                                        <div class="mh-inline">
                                            <form method="post">
                                                <input type="hidden" name="action" value="benefactor_appointment_decide">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                                <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                                <input type="hidden" name="decision" value="accept">
                                                <button type="submit" class="mh-btn-secondary">Accept Appointment</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="action" value="benefactor_appointment_decide">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                                <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                                <input type="hidden" name="decision" value="deny">
                                                <button type="submit" class="mh-btn-danger">Decline</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>

                    <?php if (!empty($activeAppointments)): ?>
                        <div class="mh-small" style="margin-bottom: 8px;">Active appointments</div>
                        <table class="mh-table">
                            <thead>
                                <tr>
                                    <th>Owner</th>
                                    <th>Allocations</th>
                                    <th>Claim</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activeAppointments as $o): ?>
                                <?php
                                    $ou = (string)($o['owner_username'] ?? '');
                                    $ownerAssets = $ou !== '' ? mh_benefactors_asset_snapshot($ou) : [];
                                    $ownerBenefactors = [];
                                    $ownerRules = [];
                                    try {
                                        $stb = $pdo->prepare("SELECT benefactor_username FROM benefactors WHERE owner_username = ? AND status = 'active'");
                                        $stb->execute([$ou]);
                                        $ownerBenefactors = $stb->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                        $str = $pdo->prepare("SELECT * FROM benefactor_asset_rules WHERE owner_username = ?");
                                        $str->execute([$ou]);
                                        $ownerRules = $str->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                    } catch (Throwable $e) {
                                        $ownerBenefactors = [];
                                        $ownerRules = [];
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string)($o['owner_name'] ?? ''), ENT_QUOTES); ?></div>
                                        <div class="mh-small"><?php echo htmlspecialchars($ou, ENT_QUOTES); ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($ownerAssets)): ?>
                                            <?php foreach ($ownerAssets as $a): ?>
                                                <?php
                                                    $at = (string)($a['type'] ?? '');
                                                    $label = (string)($a['label'] ?? $at);
                                                    $qty = (int)floor((float)($a['qty'] ?? 0));
                                                    $allocPct = mh_benefactors_compute_allocations($ownerBenefactors, $ownerRules, $at);
                                                    $pct = isset($allocPct[$username]) ? (float)$allocPct[$username] : 0.0;
                                                    $amt = (int)floor($qty * ($pct / 100.0));
                                                ?>
                                                <div class="mh-small" style="margin-bottom: 6px;">
                                                    <?php echo htmlspecialchars($label, ENT_QUOTES); ?>:
                                                    <?php echo number_format($amt); ?> (<?php echo number_format($pct, 2); ?>%)
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="mh-small">No assets detected.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post">
                                            <input type="hidden" name="action" value="benefactor_claim_initiate">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                            <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                            <button type="submit" class="mh-btn-secondary">Create Claim</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="mh-small">No active appointments.</div>
                    <?php endif; ?>

                    <?php if (!empty($myClaims)): ?>
                        <div style="margin-top: 16px;">
                            <div class="mh-small" style="margin-bottom: 8px;">Your initiated claims</div>
                            <table class="mh-table">
                                <thead>
                                    <tr>
                                        <th>Owner</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($myClaims as $c): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($c['owner_username'] ?? ''), ENT_QUOTES); ?></td>
                                        <td class="mh-small"><?php echo htmlspecialchars((string)($c['status'] ?? ''), ENT_QUOTES); ?></td>
                                        <td>
                                            <div class="mh-inline">
                                                <?php if (!empty($c['kyc_room_id'])): ?>
                                                    <a class="mh-btn mh-btn-primary" style="text-decoration:none;" href="/auth/id/capture.php?room_id=<?php echo urlencode((string)$c['kyc_room_id']); ?>&k=mosip&return_url=<?php echo rawurlencode('/hub/settings.php'); ?>">Upload Proof</a>
                                                <?php endif; ?>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="benefactor_claim_execute">
                                                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                                    <input type="hidden" name="claim_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                                    <button type="submit" class="mh-btn-secondary">Execute Transfer</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="mh-small" style="margin-top: 8px;">Execute Transfer requires your KYC status to be verified.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="settings-card">
                <h2>BENEFACTORS</h2>
                <div class="mh-small" style="margin-bottom: 12px;">Benefactors are managed in the Equity module.</div>
                <a class="mh-btn-secondary" style="display:inline-block; text-decoration:none; padding: 10px 14px; border-radius: 10px;" href="/hub/equity/benefactors.php">Open Benefactors</a>
            </div>
        </div>
    </div>
    </main>
    <?php if (false && !empty($pendingClaimsForMe)): ?>
        <div class="mh-modal-backdrop" id="mhBenefactorModal" style="display:flex;">
            <div class="mh-modal">
                <h3>Benefactor Allocation Request</h3>
                <div class="mh-small" style="margin-bottom: 10px;">Accept or deny allocations for open claims.</div>
                <table class="mh-table">
                    <thead>
                        <tr>
                            <th>Owner</th>
                            <th>Claim</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingClaimsForMe as $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($p['owner_username'] ?? ''), ENT_QUOTES); ?></td>
                            <td class="mh-small"><?php echo htmlspecialchars((string)($p['created_at'] ?? ''), ENT_QUOTES); ?></td>
                            <td>
                                <div class="mh-inline">
                                    <form method="post">
                                        <input type="hidden" name="action" value="benefactor_claim_decide">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                        <input type="hidden" name="claim_id" value="<?php echo (int)($p['claim_id'] ?? 0); ?>">
                                        <input type="hidden" name="decision" value="accept">
                                        <button type="submit" class="mh-btn-secondary">Accept</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="benefactor_claim_decide">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)($benefactorCsrf ?? ''), ENT_QUOTES); ?>">
                                        <input type="hidden" name="claim_id" value="<?php echo (int)($p['claim_id'] ?? 0); ?>">
                                        <input type="hidden" name="decision" value="deny">
                                        <button type="submit" class="mh-btn-danger">Deny</button>
                                    </form>
                                    <?php if (!empty($p['kyc_room_id'])): ?>
                                        <a class="mh-btn mh-btn-primary" style="text-decoration:none;" href="/auth/id/capture.php?room_id=<?php echo urlencode((string)$p['kyc_room_id']); ?>&k=mosip&return_url=<?php echo rawurlencode('/hub/settings.php'); ?>">Upload Proof</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="mh-actions">
                    <button type="button" class="mh-btn-secondary" onclick="document.getElementById('mhBenefactorModal').style.display='none'">Close</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <script>
        (function () {
            const form = document.getElementById('identityForm');
            if (!form) return;
            const msg = document.getElementById('identityClientMessage');
            const firstEl = form.querySelector('input[name="real_first_name"]');
            const lastEl = form.querySelector('input[name="real_last_name"]');
            function cleanName(v) {
                return String(v || '').trim().replace(/[^a-zA-Z\-']/g, '').toLowerCase();
            }
            function setMsg(text) {
                if (!msg) return;
                msg.textContent = text || '';
                msg.style.display = text ? 'block' : 'none';
            }
            function validate() {
                const fn = cleanName(firstEl ? firstEl.value : '');
                const ln = cleanName(lastEl ? lastEl.value : '');
                if (fn && ln && fn === ln) {
                    setMsg('Real name and surname cannot be the same.');
                    return false;
                }
                setMsg('');
                return true;
            }
            form.addEventListener('submit', (e) => {
                if (!validate()) {
                    e.preventDefault();
                    try { if (firstEl) firstEl.focus(); } catch (err) {}
                }
            });
            if (firstEl) firstEl.addEventListener('input', validate);
            if (lastEl) lastEl.addEventListener('input', validate);
        })();
    </script>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
