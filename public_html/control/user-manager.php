<?php
/**
 * User Manager
 * Control Panel for Managing Users
 * 
 * Functions:
 * 1. List Users
 * 2. Edit User Details
 * 3. Delete User
 */

require_once __DIR__ . '/../.cue/cue.php';
if (is_file(__DIR__ . '/../auth/tenant_provisioning.php')) {
    require_once __DIR__ . '/../auth/tenant_provisioning.php';
}
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/auth_classes.php';
require_once __DIR__ . '/../auth/persona_registry.php';

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

$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/user-manager.php';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/control/user-manager.php';
}
$requestUri = strtok($requestUri, '?') ?: '/control/user-manager.php';

if (isset($_SESSION['usermanager_flash_message']) && is_string($_SESSION['usermanager_flash_message'])) {
    $message = $_SESSION['usermanager_flash_message'];
    unset($_SESSION['usermanager_flash_message']);
}
if (isset($_SESSION['usermanager_flash_type']) && is_string($_SESSION['usermanager_flash_type'])) {
    $messageType = $_SESSION['usermanager_flash_type'];
    unset($_SESSION['usermanager_flash_type']);
}

// Force Hub Realm for consistent menu
$_SESSION['current_realm'] = 'hub';

// Auth Check (Admin Only)
if (!isset($_SESSION['mh_auth_user'])) {
    header('Location: /auth/login.php');
    exit;
}

// Connect to Biometrics DB (Authoritative User Source)
try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    $pdo = database_getConnectionById('biometrics');
} catch (Exception $e) {
    die("Database Connection Error: " . $e->getMessage());
}
if ($pdo instanceof PDO && function_exists('mh_ensure_user_real_name_schema')) {
    mh_ensure_user_real_name_schema($pdo);
}

if (!isset($message) || !is_string($message)) {
    $message = '';
}
if (!isset($messageType) || !is_string($messageType)) {
    $messageType = 'info';
}

function usermanager_flash_redirect(string $requestUri, string $message, string $messageType = 'info'): never {
    $_SESSION['usermanager_flash_message'] = $message;
    $_SESSION['usermanager_flash_type'] = $messageType;
    header('Location: ' . $requestUri, true, 303);
    exit;
}

function usermanager_tenantSafe(string $tenantId): string {
    $safe = preg_replace('/[^a-zA-Z0-9:_-]/', '_', $tenantId);
    $safe = str_replace(':', '_', (string)$safe);
    $safe = preg_replace('/_+/', '_', (string)$safe);
    return trim((string)$safe, '_');
}

function usermanager_deleteDirRecursive(string $dir): bool {
    $real = realpath($dir);
    if ($real === false || !is_dir($real)) {
        return false;
    }
    $items = scandir($real);
    if (!is_array($items)) {
        return false;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $real . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            usermanager_deleteDirRecursive($path);
        } else {
            @unlink($path);
        }
    }
    return @rmdir($real);
}

function usermanager_removeTenantContext(string $tenantId): array {
    $ctxFile = '/data/config/tenant-contexts.json';
    if (!is_file($ctxFile)) {
        return ['ok' => false, 'message' => 'tenant-contexts.json missing'];
    }
    $ctx = json_decode((string)file_get_contents($ctxFile), true);
    if (!is_array($ctx)) {
        return ['ok' => false, 'message' => 'tenant-contexts.json invalid'];
    }
    if (!isset($ctx[$tenantId])) {
        return ['ok' => true, 'removed' => false];
    }
    unset($ctx[$tenantId]);
    $ok = file_put_contents($ctxFile, json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    return ['ok' => $ok !== false, 'removed' => true];
}

function usermanager_ensure_deleted_users_table(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_deleted_users (
            username VARCHAR(255) NOT NULL PRIMARY KEY,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_by VARCHAR(255) DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
    }
}

function usermanager_mark_deleted(PDO $pdo, string $username, ?string $deletedBy = null, ?string $reason = null): void {
    $username = trim($username);
    if ($username === '') return;
    usermanager_ensure_deleted_users_table($pdo);
    $deletedBy = is_string($deletedBy) ? trim($deletedBy) : '';
    $reason = is_string($reason) ? trim($reason) : '';
    try {
        $stmt = $pdo->prepare("INSERT INTO mh_deleted_users (username, deleted_by, reason) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE deleted_at = CURRENT_TIMESTAMP, deleted_by = VALUES(deleted_by), reason = VALUES(reason)");
        $stmt->execute([$username, $deletedBy !== '' ? $deletedBy : null, $reason !== '' ? $reason : null]);
    } catch (Throwable $e) {
    }
}

function usermanager_buildAudit(PDO $pdo): array {
    $cols = [];
    try { $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN, 0); } catch (Throwable $e) { $cols = []; }
    $hasFirst = in_array('real_first_name', $cols, true);
    $hasLast = in_array('real_last_name', $cols, true);

    $total = 0;
    try { $total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); } catch (Throwable $e) { $total = 0; }

    $missingRealParts = null;
    $sameFirstLast = null;
    $missingSample = [];

    if ($hasFirst && $hasLast) {
        try {
            $missingRealParts = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(NULLIF(real_first_name,''),'')='' OR COALESCE(NULLIF(real_last_name,''),'')=''")->fetchColumn();
        } catch (Throwable $e) {
            $missingRealParts = null;
        }
        try {
            $sameFirstLast = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE COALESCE(NULLIF(real_first_name,''),'')<>'' AND COALESCE(NULLIF(real_last_name,''),'')<>'' AND LOWER(real_first_name)=LOWER(real_last_name)")->fetchColumn();
        } catch (Throwable $e) {
            $sameFirstLast = null;
        }
        try {
            $stmt = $pdo->query("SELECT username, name, real_first_name, real_last_name, tenant_id, persona_name, role FROM users WHERE COALESCE(NULLIF(real_first_name,''),'')='' OR COALESCE(NULLIF(real_last_name,''),'')='' ORDER BY id DESC LIMIT 20");
            if ($stmt) $missingSample = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $missingSample = [];
        }
    }

    $tenantRows = [];
    try {
        $stmt = $pdo->query("SELECT username, tenant_id FROM users WHERE tenant_id IS NOT NULL AND tenant_id <> ''");
        $tenantRows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $tenantRows = [];
    }

    $tenant = [
        'with_tenant_id' => count($tenantRows),
        'checked' => 0,
        'tenant_db_ok' => 0,
        'tenant_db_missing' => 0,
        'tenant_db_error' => 0,
        'tenant_db_missing_sample' => [],
    ];

    foreach ($tenantRows as $r) {
        $tenant['checked']++;
        if ($tenant['checked'] > 200) break;
        $tenantId = isset($r['tenant_id']) ? trim((string)$r['tenant_id']) : '';
        if ($tenantId === '') continue;
        $dbid = function_exists('mh_resolve_tenant_db_config_id') ? (string)mh_resolve_tenant_db_config_id($tenantId) : '';
        if ($dbid === '') {
            $tenant['tenant_db_missing']++;
            if (count($tenant['tenant_db_missing_sample']) < 20) $tenant['tenant_db_missing_sample'][] = ['username' => (string)($r['username'] ?? ''), 'tenant_id' => $tenantId, 'db_config_id' => ''];
            continue;
        }
        try {
            $tpdo = database_getConnectionById($dbid);
            if ($tpdo instanceof PDO) {
                $tenant['tenant_db_ok']++;
            } else {
                $tenant['tenant_db_missing']++;
                if (count($tenant['tenant_db_missing_sample']) < 20) $tenant['tenant_db_missing_sample'][] = ['username' => (string)($r['username'] ?? ''), 'tenant_id' => $tenantId, 'db_config_id' => $dbid];
            }
        } catch (Throwable $e) {
            $tenant['tenant_db_error']++;
        }
    }

    return [
        'generated_at' => gmdate('c'),
        'biometrics' => [
            'total' => $total,
            'has_real_first_name' => $hasFirst,
            'has_real_last_name' => $hasLast,
            'missing_real_parts' => $missingRealParts,
            'same_first_last' => $sameFirstLast,
            'missing_sample' => $missingSample,
        ],
        'tenant' => $tenant,
    ];
}

function usermanager_getNavigationConfigId(): ?string {
    $ctxFile = '/data/config/database-contexts.json';
    if (!is_file($ctxFile)) return null;
    $ctx = json_decode((string)file_get_contents($ctxFile), true);
    if (!is_array($ctx)) return null;
    $dir = $ctx['directory_mappings'] ?? null;
    if (is_array($dir)) {
        $id = $dir['/templates/menus'] ?? ($dir['/templates/global-ui'] ?? null);
        if (is_string($id) && $id !== '') return $id;
    }
    return null;
}

function usermanager_cleanupUserResources(PDO $pdoBio, string $username): array {
    $out = ['tenant_id' => null, 'deleted' => [], 'warnings' => []];
    $stmt = $pdoBio->prepare('SELECT id, tenant_id, device_id, persona_name FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $out['warnings'][] = 'user_not_found';
        return $out;
    }
    $userId = (int)($row['id'] ?? 0);
    $tenantId = is_string($row['tenant_id'] ?? null) ? (string)$row['tenant_id'] : '';
    $deviceId = is_string($row['device_id'] ?? null) ? trim((string)$row['device_id']) : '';
    $personaName = is_string($row['persona_name'] ?? null) ? trim((string)$row['persona_name']) : '';
    if (trim($tenantId) === '' && trim($username) !== '') {
        $tenantId = 'user:' . trim($username);
    }
    $out['tenant_id'] = $tenantId;

    $personaTenantIds = [];
    if ($personaName !== '') {
        $personaTenantIds['persona:' . $personaName] = true;
    }
    $personaTenantIds = array_keys($personaTenantIds);

    $tablesByUserId = [
        ['table' => 'user_sessions', 'col' => 'user_id'],
        ['table' => 'user_device_tokens', 'col' => 'user_id'],
    ];
    foreach ($tablesByUserId as $t) {
        try {
            $pdoBio->prepare("DELETE FROM `{$t['table']}` WHERE `{$t['col']}` = ?")->execute([$userId]);
            $out['deleted'][] = $t['table'];
        } catch (Throwable $e) {
            $out['warnings'][] = $t['table'] . ':' . $e->getMessage();
        }
    }
    $tablesByUsername = [
        ['table' => 'remember_me_tokens', 'col' => 'username'],
    ];
    foreach ($tablesByUsername as $t) {
        try {
            $pdoBio->prepare("DELETE FROM `{$t['table']}` WHERE `{$t['col']}` = ?")->execute([$username]);
            $out['deleted'][] = $t['table'];
        } catch (Throwable $e) {
            $out['warnings'][] = $t['table'] . ':' . $e->getMessage();
        }
    }

    try {
        if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_ensure_schema')) {
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            mh_tokenomics_ensure_schema($pdoTok);
            $pdoTok->prepare("DELETE FROM mh_asset_transactions WHERE username = ?")->execute([$username]);
            $out['deleted'][] = 'mh_asset_transactions';
            $pdoTok->prepare("DELETE FROM mh_asset_ledger WHERE username = ?")->execute([$username]);
            $out['deleted'][] = 'mh_asset_ledger';
            try {
                $pdoTok->prepare("DELETE FROM mh_stripe_checkout_orders WHERE username = ?")->execute([$username]);
                $out['deleted'][] = 'mh_stripe_checkout_orders';
            } catch (Throwable $e) {
                $out['warnings'][] = 'mh_stripe_checkout_orders:' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $out['warnings'][] = 'mh_asset_transactions:' . $e->getMessage();
    }

    try {
        if (function_exists('getEquityConnection')) {
            $pdoEq = getEquityConnection();
            if ($pdoEq instanceof PDO) {
                $pdoEq->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try {
                    $pdoEq->prepare("DELETE FROM equity_culture_coin_orders WHERE username = ?")->execute([$username]);
                    $out['deleted'][] = 'equity_culture_coin_orders';
                } catch (Throwable $e) {
                    $out['warnings'][] = 'equity_culture_coin_orders:' . $e->getMessage();
                }
            }
        }
    } catch (Throwable $e) {
        $out['warnings'][] = 'equity_db:' . $e->getMessage();
    }

    try {
        if (function_exists('database_getConnectionById')) {
            $pdoFin = null;
            try { $pdoFin = database_getConnectionById('finora'); } catch (Throwable) { $pdoFin = null; }
            if ($pdoFin instanceof PDO && $tenantId !== '') {
                $pdoFin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $finoraTables = [
                    'mh_finora_entries',
                    'mh_finora_categories',
                    'mh_finora_methods',
                    'mh_finora_budgets',
                    'mh_finora_goal_contributions',
                    'mh_finora_goals',
                    'mh_finora_recurring_rules',
                    'mh_finora_import_batches',
                    'mh_finora_backups',
                    'mh_finora_activity',
                ];
                foreach ($finoraTables as $t) {
                    try {
                        $pdoFin->prepare("DELETE FROM `{$t}` WHERE tenant_id = ? AND user_id = ?")->execute([$tenantId, $username]);
                        $out['deleted'][] = 'finora_' . $t;
                    } catch (Throwable $e) {
                        $out['warnings'][] = 'finora_' . $t . ':' . $e->getMessage();
                    }
                }
            }
        }
    } catch (Throwable) {
    }

    try {
        $cols = $pdoBio->query("SHOW COLUMNS FROM webauthn_credentials");
        if ($cols) {
            $rows = $cols->fetchAll(PDO::FETCH_ASSOC);
            $fields = [];
            foreach ($rows as $r) {
                $f = isset($r['Field']) ? (string)$r['Field'] : '';
                if ($f !== '') {
                    $fields[$f] = true;
                }
            }
            if (isset($fields['user_id'])) {
                $candidateIds = array_values(array_unique(array_filter([
                    $username,
                    $userId > 0 ? (string)$userId : '',
                    $tenantId,
                    $deviceId,
                    $personaName,
                ], function ($v) { return is_string($v) && trim($v) !== ''; })));
                if (!empty($candidateIds)) {
                    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
                    $pdoBio->prepare("DELETE FROM webauthn_credentials WHERE user_id IN ($placeholders)")->execute($candidateIds);
                    $out['deleted'][] = 'webauthn_credentials';
                }
            } elseif (isset($fields['username'])) {
                $pdoBio->prepare("DELETE FROM webauthn_credentials WHERE username = ?")->execute([(string)$username]);
                $out['deleted'][] = 'webauthn_credentials';
            } elseif (isset($fields['user_handle'])) {
                $candidateIds = array_values(array_unique(array_filter([
                    $username,
                    $userId > 0 ? (string)$userId : '',
                    $tenantId,
                    $deviceId,
                    $personaName,
                ], function ($v) { return is_string($v) && trim($v) !== ''; })));
                if (!empty($candidateIds)) {
                    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
                    $pdoBio->prepare("DELETE FROM webauthn_credentials WHERE user_handle IN ($placeholders)")->execute($candidateIds);
                    $out['deleted'][] = 'webauthn_credentials';
                }
            }
        }
    } catch (Throwable $e) {
        $out['warnings'][] = 'webauthn_credentials:' . $e->getMessage();
    }

    if ($tenantId !== '') {
        if (function_exists('mh_deprovision_tenant_resources')) {
            try {
                $r = mh_deprovision_tenant_resources($tenantId);
                if (is_array($r)) {
                    if (!empty($r['deleted']) && is_array($r['deleted'])) {
                        foreach ($r['deleted'] as $d) {
                            if (is_string($d) && $d !== '') $out['deleted'][] = $d;
                        }
                    }
                    if (!empty($r['warnings']) && is_array($r['warnings'])) {
                        foreach ($r['warnings'] as $w) {
                            if (is_string($w) && $w !== '') $out['warnings'][] = $w;
                        }
                    }
                }
            } catch (Throwable $e) {
                $out['warnings'][] = 'tenant_deprovision:' . $e->getMessage();
            }
        } else {
            try {
                $tenantContexts = json_decode((string)@file_get_contents('/data/config/tenant-contexts.json'), true);
                $ctx = is_array($tenantContexts) ? ($tenantContexts[$tenantId] ?? null) : null;
                $vectorPath = is_array($ctx) ? (string)($ctx['vector_path'] ?? '') : '';
                $graphPath = is_array($ctx) ? (string)($ctx['graph_path'] ?? '') : '';
                if ($vectorPath !== '' && strpos($vectorPath, '/vector') === 0) {
                    usermanager_deleteDirRecursive($vectorPath);
                    $out['deleted'][] = 'vector_path';
                }
                if ($graphPath !== '' && strpos($graphPath, '/graph') === 0) {
                    usermanager_deleteDirRecursive($graphPath);
                    $out['deleted'][] = 'graph_path';
                }
            } catch (Throwable $e) {
                $out['warnings'][] = 'tenant_context_paths:' . $e->getMessage();
            }

            try {
                usermanager_removeTenantContext($tenantId);
                $out['deleted'][] = 'tenant-contexts';
            } catch (Throwable $e) {
                $out['warnings'][] = 'tenant-contexts:' . $e->getMessage();
            }

            $tenantSafe = usermanager_tenantSafe($tenantId);
            if ($tenantSafe !== '') {
                $tenantRoot = '/data/tenants/' . $tenantSafe;
                if (is_dir($tenantRoot)) {
                    usermanager_deleteDirRecursive($tenantRoot);
                    $out['deleted'][] = 'tenant_files';
                }
            }
        }
    }

    foreach ($personaTenantIds as $ptid) {
        $ptid = is_string($ptid) ? trim($ptid) : '';
        if ($ptid === '' || $ptid === $tenantId) continue;
        if (function_exists('mh_deprovision_tenant_resources')) {
            try {
                $r = mh_deprovision_tenant_resources($ptid);
                if (is_array($r)) {
                    if (!empty($r['deleted']) && is_array($r['deleted'])) {
                        foreach ($r['deleted'] as $d) {
                            if (is_string($d) && $d !== '') $out['deleted'][] = $d;
                        }
                    }
                    if (!empty($r['warnings']) && is_array($r['warnings'])) {
                        foreach ($r['warnings'] as $w) {
                            if (is_string($w) && $w !== '') $out['warnings'][] = $w;
                        }
                    }
                }
            } catch (Throwable $e) {
                $out['warnings'][] = 'persona_tenant_deprovision:' . $e->getMessage();
            }
        }
    }

    try {
        if (function_exists('mh_deprovision_tenant_by_db_config_id') && $tenantId !== '' && function_exists('mh_tenant_config_id')) {
            $cid = mh_tenant_config_id($tenantId);
            if (is_string($cid) && $cid !== '') {
                $r = mh_deprovision_tenant_by_db_config_id($cid);
                if (is_array($r) && !empty($r['warnings']) && is_array($r['warnings'])) {
                    foreach ($r['warnings'] as $w) {
                        if (is_string($w) && $w !== '') $out['warnings'][] = $w;
                    }
                }
            }
        }
    } catch (Throwable) {
    }

    try {
        if (function_exists('database_getConnectionById')) {
            $navId = usermanager_getNavigationConfigId();
            if (is_string($navId) && $navId !== '') {
                $pdoNav = database_getConnectionById($navId);
                if ($pdoNav instanceof PDO) {
                    try {
                        $pdoNav->prepare('DELETE FROM users WHERE uid = ? OR username = ?')->execute([$username, $username]);
                        $out['deleted'][] = 'nav_users';
                    } catch (Throwable $e) {
                        $out['warnings'][] = 'nav_users:' . $e->getMessage();
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $out['warnings'][] = 'nav_db:' . $e->getMessage();
    }

    return $out;
}

// --- Handle Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $usernameToDelete = $_POST['username'];
        if ($usernameToDelete === $_SESSION['mh_auth_user']) {
            $message = "You cannot delete your own account.";
            $messageType = 'error';
        } else {
            try {
                $deletedBy = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
                usermanager_mark_deleted($pdo, (string)$usernameToDelete, $deletedBy, 'user_manager_delete');
                try {
                    $pass = new MetaPasskeyAuth();
                    if (method_exists($pass, 'deleteUserCredentials')) {
                        $pass->deleteUserCredentials((string)$usernameToDelete);
                    }
                } catch (Throwable $e) {
                }
                try {
                    $pdoReg = mh_persona_registry_pdo();
                    mh_persona_registry_release_all_by_owner($pdoReg, $usernameToDelete);
                } catch (Throwable $e) {}
                $cleanup = usermanager_cleanupUserResources($pdo, $usernameToDelete);
                $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
                $stmt->execute([$usernameToDelete]);
                usermanager_flash_redirect($requestUri, "User '$usernameToDelete' deleted successfully.", 'success');
            } catch (Exception $e) {
                $message = "Error deleting user: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    if ($action === 'batch_delete') {
        $items = $_POST['usernames'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $usernamesToDelete = [];
        foreach ($items as $it) {
            if (!is_string($it)) continue;
            $u = trim($it);
            if ($u === '') continue;
            $usernamesToDelete[$u] = true;
        }
        $usernamesToDelete = array_keys($usernamesToDelete);

        $currentUser = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
        $deletedCount = 0;
        $skippedCurrent = false;
        try {
            foreach ($usernamesToDelete as $usernameToDelete) {
                if ($currentUser !== '' && $usernameToDelete === $currentUser) {
                    $skippedCurrent = true;
                    continue;
                }
                usermanager_mark_deleted($pdo, (string)$usernameToDelete, $currentUser !== '' ? $currentUser : null, 'user_manager_batch_delete');
                try {
                    $pass = new MetaPasskeyAuth();
                    if (method_exists($pass, 'deleteUserCredentials')) {
                        $pass->deleteUserCredentials((string)$usernameToDelete);
                    }
                } catch (Throwable $e) {
                }
                $cleanup = usermanager_cleanupUserResources($pdo, $usernameToDelete);
                try {
                    $pdoReg = mh_persona_registry_pdo();
                    mh_persona_registry_release_all_by_owner($pdoReg, $usernameToDelete);
                } catch (Throwable $e) {}
                $stmt = $pdo->prepare("DELETE FROM users WHERE username = ?");
                $stmt->execute([$usernameToDelete]);
                $deletedCount++;
            }
            $msg = "Deleted {$deletedCount} user(s).";
            if ($skippedCurrent) {
                $msg .= " Skipped current session user.";
            }
            usermanager_flash_redirect($requestUri, $msg, 'success');
        } catch (Throwable $e) {
            $message = "Batch delete failed: " . $e->getMessage();
            $messageType = 'error';
        }
    }
    
    if ($action === 'edit') {
        $origUsername = trim((string)($_POST['original_username'] ?? ''));
        $newUsername = trim((string)($_POST['username'] ?? $origUsername));
        $firstName = trim((string)($_POST['real_first_name'] ?? ($_POST['first_name'] ?? ($_POST['firstName'] ?? ''))));
        $surname = trim((string)($_POST['real_last_name'] ?? ($_POST['last_name'] ?? ($_POST['surname'] ?? ($_POST['lastName'] ?? '')))));
        $name = trim((string)($_POST['name'] ?? ''));
        $personaName = trim((string)($_POST['persona_name'] ?? ''));
        
        try {
            if ($origUsername === '' || $newUsername === '') {
                throw new Exception('Username is required.');
            }
            if (strpos($newUsername, '@') !== false) {
                throw new Exception('Username cannot contain "@".');
            }
            if (preg_match('/\s/', $newUsername)) {
                throw new Exception('Username cannot contain spaces.');
            }
            if (!preg_match('/^[a-zA-Z0-9]+$/', $newUsername) || strlen($newUsername) < 5) {
                throw new Exception('Username must be at least 5 characters and contain letters and numbers only.');
            }
            if ($name !== '' && ($firstName === '' || $surname === '')) {
                $parts = preg_split('/\s+/', $name);
                $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
                if (count($parts) >= 2) {
                    if ($firstName === '') $firstName = (string)$parts[0];
                    if ($surname === '') $surname = (string)$parts[count($parts) - 1];
                }
            }
            if ($name === '' && $firstName !== '' && $surname !== '') {
                $name = trim($firstName . ' ' . $surname);
            }
            if ($firstName === '' || $surname === '') throw new Exception('Real name and surname are required.');
            if (strpos($firstName, '@') !== false || strpos($surname, '@') !== false) throw new Exception('Real name/surname cannot contain "@".');
            $firstClean = preg_replace("/[^a-zA-Z\\-']/u", '', $firstName);
            $surnameClean = preg_replace("/[^a-zA-Z\\-']/u", '', $surname);
            if (!is_string($firstClean) || strlen($firstClean) < 2) throw new Exception('Real name must be at least 2 characters.');
            if (!is_string($surnameClean) || strlen($surnameClean) < 2) throw new Exception('Real surname must be at least 2 characters.');
            if (strcasecmp($firstClean, $surnameClean) === 0) throw new Exception('Real name and surname cannot be the same.');
            if ($newUsername !== $origUsername) {
                $chk = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
                $chk->execute([$newUsername]);
                if ((bool)$chk->fetchColumn()) {
                    throw new Exception('Username already exists.');
                }
            }
            try {
                $stmtCols = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'");
                if ($stmtCols->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN name VARCHAR(255) DEFAULT NULL AFTER username");
                }
            } catch (Throwable $e) {}
            try {
                $stmtCols = $pdo->query("SHOW COLUMNS FROM users LIKE 'persona_name'");
                if ($stmtCols->rowCount() === 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN persona_name VARCHAR(255) DEFAULT NULL AFTER name");
                }
            } catch (Throwable $e) {}
            if (function_exists('mh_ensure_user_real_name_schema')) {
                mh_ensure_user_real_name_schema($pdo);
            }

            $oldPersona = '';
            $oldTenantId = '';
            try {
                $stmt = $pdo->prepare("SELECT persona_name, tenant_id FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$origUsername]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $oldPersona = is_array($row) && isset($row['persona_name']) ? trim((string)$row['persona_name']) : '';
                $oldTenantId = is_array($row) && isset($row['tenant_id']) ? trim((string)$row['tenant_id']) : '';
            } catch (Throwable $e) {}
            if ($oldTenantId === '') {
                $oldTenantId = 'user:' . $origUsername;
            }
            $newTenantId = 'user:' . $newUsername;

            $pdo->beginTransaction();
            try {
                $pdoReg = null;
                $claimedPersona = false;
                if (trim($personaName) !== '' && $personaName !== $oldPersona) {
                    $pdoReg = mh_persona_registry_pdo();
                    if (!mh_persona_registry_claim($pdoReg, $newUsername, $personaName)) {
                        throw new Exception('Persona name is already taken.');
                    }
                    $claimedPersona = true;
                }
                if ($newUsername !== $origUsername) {
                    $stmt = $pdo->prepare("UPDATE users SET username = ?, tenant_id = ?, name = ?, real_first_name = ?, real_last_name = ?, persona_name = ? WHERE username = ?");
                    $stmt->execute([$newUsername, $newTenantId, $name, $firstName, $surname, $personaName, $origUsername]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET tenant_id = ?, name = ?, real_first_name = ?, real_last_name = ?, persona_name = ? WHERE username = ?");
                    $stmt->execute([$newTenantId, $name, $firstName, $surname, $personaName, $origUsername]);
                }

                if ($newUsername !== $origUsername) {
                    try {
                        $webauthnCols = [];
                        $cols = $pdo->query("SHOW COLUMNS FROM webauthn_credentials");
                        if ($cols) {
                            foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $r) {
                                $f = isset($r['Field']) ? (string)$r['Field'] : '';
                                if ($f !== '') {
                                    $webauthnCols[$f] = true;
                                }
                            }
                        }
                        if (!empty($webauthnCols)) {
                            if (isset($webauthnCols['user_id'])) {
                                $pdo->prepare("UPDATE webauthn_credentials SET user_id = ? WHERE user_id = ?")->execute([$newUsername, $origUsername]);
                            }
                            if (isset($webauthnCols['username'])) {
                                $pdo->prepare("UPDATE webauthn_credentials SET username = ? WHERE username = ?")->execute([$newUsername, $origUsername]);
                            }
                            if (isset($webauthnCols['user_handle'])) {
                                $pdo->prepare("UPDATE webauthn_credentials SET user_handle = ? WHERE user_handle = ?")->execute([$newUsername, $origUsername]);
                            }
                        }
                    } catch (Throwable $e) {}
                }
                if ($newUsername !== $origUsername) {
                    try {
                        $t = $pdo->query("SHOW TABLES LIKE 'remember_me_tokens'");
                        $has = $t && (bool)$t->fetchColumn();
                        if ($has) {
                            $pdo->prepare("UPDATE remember_me_tokens SET username = ? WHERE username = ?")->execute([$newUsername, $origUsername]);
                        }
                    } catch (Throwable $e) {}
                }
                $pdo->commit();
                if (!$pdoReg instanceof PDO) {
                    $pdoReg = mh_persona_registry_pdo();
                }
                if ($newUsername !== $origUsername) {
                    mh_persona_registry_update_owner($pdoReg, $origUsername, $newUsername);
                }
                if ($oldPersona !== '' && $personaName !== '' && $personaName !== $oldPersona) {
                    mh_persona_registry_release($pdoReg, $newUsername, $oldPersona);
                }
                if ($newUsername !== $origUsername) {
                    try { mh_tokenomics_migrate_username($origUsername, $newUsername); } catch (Throwable $e) {}
                    try {
                        require_once __DIR__ . '/../auth/auth_classes.php';
                        $auth = new MetaPasskeyAuth();
                        $auth->migrateUserCredentials($origUsername, $newUsername);
                    } catch (Throwable $e) {}
                }
                if ($oldTenantId !== $newTenantId) {
                    try { mh_tenant_context_move($oldTenantId, $newTenantId); } catch (Throwable $e) {}
                    try { mh_tenant_storage_move($oldTenantId, $newTenantId); } catch (Throwable $e) {}
                }
                if ($personaName !== '') {
                    $dbConfigId = function_exists('mh_resolve_tenant_db_config_id') ? mh_resolve_tenant_db_config_id($newTenantId) : '';
                    if (!is_string($dbConfigId) || $dbConfigId === '') {
                        if (function_exists('mh_provision_tenant_storage')) {
                            mh_provision_tenant_storage($newTenantId);
                        }
                        $prov = function_exists('mh_provision_tenant_database') ? mh_provision_tenant_database($newTenantId) : null;
                        $dbConfigId = is_array($prov) ? (string)($prov['db_config_id'] ?? '') : '';
                    }
                    if (is_string($dbConfigId) && $dbConfigId !== '') {
                        $pdoTenant = database_getConnectionById($dbConfigId);
                        if ($pdoTenant instanceof PDO) {
                        }
                    }
                }
            } catch (Throwable $e) {
                try {
                    if (isset($claimedPersona) && $claimedPersona === true && isset($pdoReg) && $pdoReg instanceof PDO) {
                        mh_persona_registry_release($pdoReg, $newUsername, $personaName);
                    }
                } catch (Throwable $e2) {
                }
                $pdo->rollBack();
                throw $e;
            }

            if ($origUsername === ($_SESSION['mh_auth_user'] ?? '') && $newUsername !== $origUsername) {
                $_SESSION['mh_auth_user'] = $newUsername;
            }
            $successMessage = ($newUsername === $origUsername)
                ? "User '$origUsername' updated successfully."
                : "User '$origUsername' renamed to '$newUsername' and updated successfully.";
            usermanager_flash_redirect($requestUri, $successMessage, 'success');
        } catch (Exception $e) {
            $message = "Error updating user: " . $e->getMessage();
            $messageType = 'error';
        }
    }

    if ($action !== '') {
        usermanager_flash_redirect($requestUri, $message, $messageType);
    }
}

// --- Fetch Users ---
$users = $pdo->query("SELECT id, username, name, real_first_name, real_last_name, persona_name, tenant_id, device_id, created_at, pin_hash, role FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$audit = usermanager_buildAudit($pdo);
$webauthnUserColumns = [];
try {
    $cols = $pdo->query("SHOW COLUMNS FROM webauthn_credentials");
    if ($cols) {
        $rows = $cols->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $f = isset($r['Field']) ? (string)$r['Field'] : '';
            if ($f !== '') {
                $webauthnUserColumns[$f] = true;
            }
        }
    }
} catch (Throwable $e) {
    $webauthnUserColumns = [];
}
$passkeyAuth = null;

$filteredUsers = [];
foreach ($users as $u) {
    $username = isset($u['username']) ? trim((string)$u['username']) : '';
    if ($username === '') {
        continue;
    }
    $hasPin = isset($u['pin_hash']) && is_string($u['pin_hash']) && trim($u['pin_hash']) !== '';
    $hasPasskey = false;
    $userId = (int)($u['id'] ?? 0);
    $personaName = isset($u['persona_name']) ? trim((string)$u['persona_name']) : '';
    $tenantId = isset($u['tenant_id']) ? trim((string)$u['tenant_id']) : '';
    $deviceId = isset($u['device_id']) ? trim((string)$u['device_id']) : '';
    $candidateIds = array_values(array_unique(array_filter([
        $username,
        $userId > 0 ? (string)$userId : '',
        $personaName,
        $tenantId,
        $deviceId,
    ], function ($v) { return is_string($v) && trim($v) !== ''; })));
    if (!empty($webauthnUserColumns)) {
        try {
            if (!empty($candidateIds)) {
                $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
                $where = [];
                $params = [];
                if (isset($webauthnUserColumns['user_id'])) {
                    $where[] = "user_id IN ($placeholders)";
                    $params = array_merge($params, $candidateIds);
                }
                if (isset($webauthnUserColumns['user_handle'])) {
                    $where[] = "user_handle IN ($placeholders)";
                    $params = array_merge($params, $candidateIds);
                }
                if (isset($webauthnUserColumns['username'])) {
                    $where[] = "username IN ($placeholders)";
                    $params = array_merge($params, $candidateIds);
                }
                if (!empty($where)) {
                    $stmt = $pdo->prepare("SELECT 1 FROM webauthn_credentials WHERE (" . implode(' OR ', $where) . ") LIMIT 1");
                    $stmt->execute($params);
                    $hasPasskey = (bool)$stmt->fetchColumn();
                }
            }
        } catch (Throwable $e) {
            $hasPasskey = false;
        }
    }
    if (!$hasPasskey) {
        try {
            if ($passkeyAuth === null) {
                $passkeyAuth = new MetaPasskeyAuth();
            }
            $hasPasskey = (bool)$passkeyAuth->hasUserPasskeys($username);
            if (!$hasPasskey && !empty($candidateIds)) {
                foreach ($candidateIds as $cid) {
                    if ($cid === $username) continue;
                    try {
                        if ((bool)$passkeyAuth->hasUserPasskeys($cid)) {
                            $hasPasskey = true;
                            break;
                        }
                    } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {
            $hasPasskey = false;
        }
    }
    $u['_has_pin'] = $hasPin;
    $u['_has_passkey'] = $hasPasskey;
    $filteredUsers[] = $u;
}

require_once __DIR__ . '/../templates/global-ui/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Manager | Meta Humans Control</title>
    <?php include_once __DIR__ . '/../templates/global-ui/includes/complete-head.php'; ?>
    <style>
        :root {
            --primary: #00d4ff;
            --bg-dark: #1a1a1a;
            --glass: rgba(255, 255, 255, 0.05);
            --border: rgba(0, 212, 255, 0.2);
            --text-main: #e0e0e0;
            --danger: #ff4444;
            --success: #00C851;
        }
        body { background-color: #1a1a1a !important; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .user-manager-page .container { max-width: 1400px; margin: 0 auto; padding: 0 20px 40px; }
        .user-manager-page h1 { font-family: 'Orbitron', sans-serif; color: var(--primary); margin-bottom: 30px; }
        .user-manager-page .toolbar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin: 0 0 16px; }
        .user-manager-page .toolbar-left { display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
        .user-manager-page .toolbar-right { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .user-manager-page .search-input { width: 340px; max-width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(0,0,0,0.35); color: #e0e0e0; }
        .user-manager-page .page-size-select { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(0,0,0,0.35); color: #e0e0e0; }
        .user-manager-page .pager-btn { padding: 8px 12px; border-radius: 10px; border: 1px solid rgba(0, 212, 255, 0.25); background: rgba(0,0,0,0.2); color: #e0e0e0; cursor:pointer; }
        .user-manager-page .pager-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .user-manager-page .pager-meta { color: #9aa; font-size: 0.9rem; }
        .user-manager-page .table-wrap { overflow-x: auto; }
        .user-manager-page table { min-width: 0; }
        
        .user-manager-page .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; backdrop-filter: blur(10px); }
        
        .user-manager-page table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .user-manager-page th, .user-manager-page td { padding: 15px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .user-manager-page th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; text-transform: uppercase; }
        .user-manager-page tr:hover { background: rgba(255,255,255,0.02); }

        .user-manager-page th:last-child,
        .user-manager-page td:last-child {
            position: sticky;
            right: 0;
            background: rgba(26, 26, 26, 0.98);
            border-left: 1px solid rgba(255,255,255,0.06);
            z-index: 2;
        }
        .user-manager-page thead th:last-child { z-index: 3; }
        .user-manager-page .actions-cell { min-width: 140px; }
        .user-manager-page .actions-stack { display: flex; flex-direction: column; gap: 10px; align-items: stretch; }
        .user-manager-page .actions-stack form { margin: 0; }
        
        .user-manager-page .btn { display: inline-flex; align-items: center; justify-content: center; padding: 8px 16px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; text-transform: uppercase; font-size: 0.8rem; transition: all 0.3s; width: 100%; box-sizing: border-box; }
        .user-manager-page .btn-primary { background: var(--primary); color: #000; }
        .user-manager-page .btn-danger { background: transparent; border: 1px solid var(--danger); color: var(--danger); }
        .user-manager-page .btn-danger:hover { background: var(--danger); color: #fff; }
        .user-manager-page .btn-edit { background: transparent; border: 1px solid var(--primary); color: var(--primary); }
        .user-manager-page .btn-edit:hover { background: var(--primary); color: #000; }
        
        .user-manager-page .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; border: 1px solid transparent; }
        .user-manager-page .alert-info { background: rgba(0, 212, 255, 0.1); border-color: var(--primary); color: var(--primary); }
        .user-manager-page .alert-error { background: rgba(255, 68, 68, 0.1); border-color: var(--danger); color: var(--danger); }
        .user-manager-page .alert-success { background: rgba(0, 200, 81, 0.1); border-color: var(--success); color: var(--success); }
        
        /* Modal */
        .user-manager-page .modal { display: none; position: fixed; z-index: 20000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.8); backdrop-filter: blur(5px); padding: 16px; box-sizing: border-box; }
        .user-manager-page .modal-content { background-color: #1a1a1a; margin: 0 auto; padding: 24px; border: 1px solid var(--primary); width: min(520px, calc(100vw - 32px)); border-radius: 12px; box-shadow: 0 0 50px rgba(0, 212, 255, 0.2); max-height: calc(100vh - 32px); overflow: auto; }
        .user-manager-page .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .user-manager-page .close:hover { color: #fff; }
        
        .user-manager-page input { background: rgba(0,0,0,0.3); border: 1px solid var(--border); color: #fff; padding: 12px; width: 100%; margin-bottom: 15px; border-radius: 4px; box-sizing: border-box; }
        .user-manager-page label { display: block; margin-bottom: 5px; color: var(--primary); font-size: 0.9rem; }
    </style>
</head>
<body class="user-manager-page">
    <?php renderGlobalHeader(); ?>
    
    <main class="main-content">
    <div class="container">
        <h1>User Manager</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="panel" style="margin-bottom: 18px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0; font-family:'Orbitron',sans-serif; color:var(--primary);">Audit Report</h2>
                    <div style="opacity:0.8; font-size:12px; margin-top:6px;">Generated: <?php echo htmlspecialchars((string)($audit['generated_at'] ?? ''), ENT_QUOTES); ?></div>
                </div>
            </div>
            <div style="margin-top: 14px; display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px;">
                <div class="panel" style="margin:0;">
                    <div style="font-weight:700; margin-bottom:8px;">Biometrics Users</div>
                    <div>Total users: <strong><?php echo (int)($audit['biometrics']['total'] ?? 0); ?></strong></div>
                    <div>Has real_first_name: <strong><?php echo !empty($audit['biometrics']['has_real_first_name']) ? 'Yes' : 'No'; ?></strong></div>
                    <div>Has real_last_name: <strong><?php echo !empty($audit['biometrics']['has_real_last_name']) ? 'Yes' : 'No'; ?></strong></div>
                    <div>Missing real parts: <strong><?php echo htmlspecialchars((string)($audit['biometrics']['missing_real_parts'] ?? 'N/A'), ENT_QUOTES); ?></strong></div>
                    <div>Same first/last: <strong><?php echo htmlspecialchars((string)($audit['biometrics']['same_first_last'] ?? 'N/A'), ENT_QUOTES); ?></strong></div>
                </div>
                <div class="panel" style="margin:0;">
                    <div style="font-weight:700; margin-bottom:8px;">Tenant Storage</div>
                    <div>Users with tenant_id: <strong><?php echo (int)($audit['tenant']['with_tenant_id'] ?? 0); ?></strong></div>
                    <div>Checked: <strong><?php echo (int)($audit['tenant']['checked'] ?? 0); ?></strong></div>
                    <div>Tenant DB OK: <strong><?php echo (int)($audit['tenant']['tenant_db_ok'] ?? 0); ?></strong></div>
                    <div>Tenant DB missing: <strong><?php echo (int)($audit['tenant']['tenant_db_missing'] ?? 0); ?></strong></div>
                    <div>Tenant DB errors: <strong><?php echo (int)($audit['tenant']['tenant_db_error'] ?? 0); ?></strong></div>
                </div>
            </div>

            <?php $missingSample = isset($audit['biometrics']['missing_sample']) && is_array($audit['biometrics']['missing_sample']) ? $audit['biometrics']['missing_sample'] : []; ?>
            <?php if (!empty($missingSample)): ?>
                <div style="margin-top: 14px;">
                    <div style="font-weight:700; margin-bottom:8px; color:#ff7b7b;">Users Missing Real Name/Surname</div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Legacy Name</th>
                                    <th>Real Name</th>
                                    <th>Real Surname</th>
                                    <th>Tenant ID</th>
                                    <th>Role</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($missingSample as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($r['username'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['real_first_name'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['real_last_name'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['tenant_id'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['role'] ?? ''), ENT_QUOTES); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php $tenantMissing = isset($audit['tenant']['tenant_db_missing_sample']) && is_array($audit['tenant']['tenant_db_missing_sample']) ? $audit['tenant']['tenant_db_missing_sample'] : []; ?>
            <?php if (!empty($tenantMissing)): ?>
                <div style="margin-top: 14px;">
                    <div style="font-weight:700; margin-bottom:8px; color:#ff7b7b;">Tenant DB Missing (Sample)</div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Tenant ID</th>
                                    <th>DB Config ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tenantMissing as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($r['username'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['tenant_id'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo htmlspecialchars((string)($r['db_config_id'] ?? ''), ENT_QUOTES); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="toolbar">
                <div class="toolbar-left">
                    <input id="userSearch" class="search-input" type="text" placeholder="Search users (username, real name, surname, persona)..." autocomplete="off">
                    <select id="pageSize" class="page-size-select">
                        <option value="all">All</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                    <button type="button" id="deleteSelected" class="pager-btn" style="border-color: rgba(255,0,0,0.35); color: #ff4d4d;">Delete Selected</button>
                </div>
                <div class="toolbar-right">
                    <button type="button" id="prevPage" class="pager-btn">Prev</button>
                    <span id="pageInfo" class="pager-meta"></span>
                    <button type="button" id="nextPage" class="pager-btn">Next</button>
                </div>
            </div>
            <div class="table-wrap">
            <table id="usersTable">
                <thead>
                    <tr>
                        <th style="width:44px; text-align:center;"><input type="checkbox" id="selectAll"></th>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Real Name</th>
                        <th>Real Surname</th>
                        <th>Persona Name</th>
                        <th>PIN</th>
                        <th>Passkey</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filteredUsers as $u): ?>
                        <?php
                            $realFirstRaw = isset($u['real_first_name']) ? trim((string)$u['real_first_name']) : '';
                            $realLastRaw = isset($u['real_last_name']) ? trim((string)$u['real_last_name']) : '';
                            if (($realFirstRaw === '' || $realLastRaw === '') && isset($u['name'])) {
                                $realNameRaw = trim((string)$u['name']);
                                $realParts = $realNameRaw !== '' ? preg_split('/\s+/', $realNameRaw) : [];
                                $realParts = is_array($realParts) ? array_values(array_filter(array_map('trim', $realParts), fn($p) => $p !== '')) : [];
                                if (count($realParts) >= 2) {
                                    if ($realFirstRaw === '') $realFirstRaw = (string)$realParts[0];
                                    if ($realLastRaw === '') $realLastRaw = (string)$realParts[count($realParts) - 1];
                                }
                            }
                            $realFirstOk = $realFirstRaw !== '' && mb_strlen($realFirstRaw) >= 2;
                            $realLastOk = $realLastRaw !== '' && mb_strlen($realLastRaw) >= 2;
                        ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" class="rowSelect" value="<?php echo htmlspecialchars((string)$u['username'], ENT_QUOTES); ?>">
                            </td>
                            <td>#<?php echo $u['id']; ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td<?php echo $realFirstOk ? '' : ' style="color:#ff7b7b;"'; ?>>
                                <?php echo htmlspecialchars($realFirstOk ? $realFirstRaw : ($realFirstRaw !== '' ? $realFirstRaw : 'Incomplete')); ?>
                            </td>
                            <td<?php echo $realLastOk ? '' : ' style="color:#ff7b7b;"'; ?>>
                                <?php echo htmlspecialchars($realLastOk ? $realLastRaw : ($realLastRaw !== '' ? $realLastRaw : 'Incomplete')); ?>
                            </td>
                            <td><?php echo htmlspecialchars($u['persona_name'] ?? ''); ?></td>
                            <td><?php echo !empty($u['_has_pin']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo !empty($u['_has_passkey']) ? 'Yes' : 'No'; ?></td>
                            <td><?php echo $u['created_at'] ?? 'N/A'; ?></td>
                            <td class="actions-cell">
                                <div class="actions-stack">
                                <button type="button" class="btn btn-edit user-manager-edit-btn"
                                    data-username="<?php echo htmlspecialchars((string)$u['username'], ENT_QUOTES); ?>"
                                    data-name="<?php echo htmlspecialchars((string)($u['name'] ?? ''), ENT_QUOTES); ?>"
                                    data-real-first="<?php echo htmlspecialchars((string)($u['real_first_name'] ?? ''), ENT_QUOTES); ?>"
                                    data-real-last="<?php echo htmlspecialchars((string)($u['real_last_name'] ?? ''), ENT_QUOTES); ?>"
                                    data-persona-name="<?php echo htmlspecialchars((string)($u['persona_name'] ?? ''), ENT_QUOTES); ?>"
                                >Edit</button>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($u['username']); ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    </main>
    
    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditModal()">&times;</span>
            <h2 style="margin-top:0; color:var(--primary); font-family:'Orbitron',sans-serif;">Edit User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" id="edit_original_username" name="original_username">
                
                <label>Username</label>
                <input type="text" id="edit_username" name="username">

                <label>Real Name</label>
                <input type="text" id="edit_real_first_name" name="real_first_name">

                <label>Real Surname</label>
                <input type="text" id="edit_real_last_name" name="real_last_name">
                <div style="opacity: 0.8; font-size: 12px; margin-top: 6px; margin-bottom: 10px;">Exactly as on your ID/Passport/Drivers License as this will become important for payout requests.</div>

                <label>Persona Name</label>
                <input type="text" id="edit_persona_name" name="persona_name">
                
                <button type="submit" class="btn btn-primary" style="width:100%; padding:12px;">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        window.openEditModal = function(username, name, realFirst, realLast, personaName) {
            document.getElementById('edit_original_username').value = username;
            document.getElementById('edit_username').value = username;
            const n = (name || '').trim();
            let fn = (realFirst || '').trim();
            let ln = (realLast || '').trim();
            if ((!fn || !ln) && n) {
                const parts = n.split(/\s+/).filter(Boolean);
                if (parts.length >= 2) {
                    if (!fn) fn = parts[0];
                    if (!ln) ln = parts[parts.length - 1];
                } else {
                    if (!fn) fn = n;
                    if (!ln) ln = n;
                }
            }
            document.getElementById('edit_real_first_name').value = fn;
            document.getElementById('edit_real_last_name').value = ln;
            document.getElementById('edit_persona_name').value = personaName || '';
            document.getElementById('editModal').style.display = "block";
        };
        
        window.closeEditModal = function() {
            document.getElementById('editModal').style.display = "none";
        };

        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.user-manager-edit-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    window.openEditModal(
                        btn.getAttribute('data-username') || '',
                        btn.getAttribute('data-name') || '',
                        btn.getAttribute('data-real-first') || '',
                        btn.getAttribute('data-real-last') || '',
                        btn.getAttribute('data-persona-name') || ''
                    );
                });
            });

            var searchInput = document.getElementById('userSearch');
            var pageSizeSelect = document.getElementById('pageSize');
            var prevBtn = document.getElementById('prevPage');
            var nextBtn = document.getElementById('nextPage');
            var pageInfo = document.getElementById('pageInfo');
            var tbody = document.querySelector('#usersTable tbody');
            if (!tbody) return;
            var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
            var currentPage = 1;

            function getPageSize() {
                if (!pageSizeSelect) return 'all';
                return pageSizeSelect.value || 'all';
            }
            function getQuery() {
                return (searchInput && searchInput.value ? String(searchInput.value) : '').trim().toLowerCase();
            }
            function filterRows() {
                var q = getQuery();
                if (!q) return rows;
                return rows.filter(function(row) {
                    return String(row.textContent || '').toLowerCase().indexOf(q) !== -1;
                });
            }
            function render() {
                var filtered = filterRows();
                var sizeVal = getPageSize();
                var pageSize = sizeVal === 'all' ? filtered.length : parseInt(sizeVal, 10);
                if (!isFinite(pageSize) || pageSize < 1) pageSize = filtered.length;
                var total = filtered.length;
                var totalPages = Math.max(1, Math.ceil(total / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;
                var start = (currentPage - 1) * pageSize;
                var end = start + pageSize;

                rows.forEach(function(r) { r.style.display = 'none'; });
                filtered.slice(start, end).forEach(function(r) { r.style.display = ''; });

                if (pageInfo) {
                    pageInfo.textContent = total === 0
                        ? 'No results'
                        : ('Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total + ' (Page ' + currentPage + '/' + totalPages + ')');
                }
                if (prevBtn) prevBtn.disabled = (currentPage <= 1);
                if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);
            }

            function resetToFirst() { currentPage = 1; render(); }
            if (searchInput) searchInput.addEventListener('input', resetToFirst);
            if (pageSizeSelect) pageSizeSelect.addEventListener('change', resetToFirst);
            if (prevBtn) prevBtn.addEventListener('click', function(){ currentPage -= 1; render(); });
            if (nextBtn) nextBtn.addEventListener('click', function(){ currentPage += 1; render(); });

            try {
                var params = new URLSearchParams(window.location.search || '');
                var qInit = (params.get('q') || params.get('user') || '').trim();
                if (qInit && searchInput && !String(searchInput.value || '').trim()) {
                    searchInput.value = qInit;
                }
            } catch (e) {
            }
            render();
        });

        document.addEventListener('DOMContentLoaded', function() {
            var selectAll = document.getElementById('selectAll');
            var deleteSelected = document.getElementById('deleteSelected');
            function getSelectedUsernames() {
                return Array.prototype.slice.call(document.querySelectorAll('.rowSelect'))
                    .filter(function(cb) { return cb && cb.checked; })
                    .map(function(cb) { return cb.value; })
                    .filter(function(v) { return typeof v === 'string' && v.trim() !== ''; });
            }
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    var checked = !!selectAll.checked;
                    document.querySelectorAll('.rowSelect').forEach(function(cb) { cb.checked = checked; });
                });
            }
            if (deleteSelected) {
                deleteSelected.addEventListener('click', function() {
                    var selected = getSelectedUsernames();
                    if (!selected.length) {
                        alert('No users selected.');
                        return;
                    }
                    if (!confirm('Delete ' + selected.length + ' selected user(s)?')) {
                        return;
                    }
                    var form = document.createElement('form');
                    form.method = 'POST';
                    var action = document.createElement('input');
                    action.type = 'hidden';
                    action.name = 'action';
                    action.value = 'batch_delete';
                    form.appendChild(action);
                    selected.forEach(function(u) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'usernames[]';
                        inp.value = u;
                        form.appendChild(inp);
                    });
                    document.body.appendChild(form);
                    form.submit();
                });
            }
        });
        
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeEditModal();
            }
        }
    </script>
    
    <?php renderGlobalFooter(); ?>
</body>
</html>
