<?php
/**
 * User-Centric Permission Manager - CUE Framework Compatible
 * Manages user-based access permissions with on/off sliders for realms, menus, and submenus
 */

// Load the CUE framework
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

require_once dirname(dirname(__DIR__)) . '/auth/auth_functions.php';
require_once dirname(dirname(__DIR__)) . '/auth/auth_classes.php';
require_once dirname(dirname(__DIR__)) . '/auth/tenant_provisioning.php';
require_once dirname(dirname(__DIR__)) . '/auth/persona_registry.php';

// Start session using secure method if available
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (function_exists('cue_autoload')) {
    cue_autoload('database');
}
$reqPath = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
$script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
if (function_exists('database_isTenantScopedRequest') && database_isTenantScopedRequest($reqPath, $script)) {
    http_response_code(404);
    exit;
}

if ((!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim($_SESSION['mh_auth_user']) === '') && function_exists('mh_load_biometrics_user')) {
    $usernameHeader = '';
    if (isset($_SERVER['HTTP_AUTH_USER']) && is_string($_SERVER['HTTP_AUTH_USER']) && $_SERVER['HTTP_AUTH_USER'] !== '') {
        $usernameHeader = (string)$_SERVER['HTTP_AUTH_USER'];
    } elseif (isset($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
        $usernameHeader = (string)$_SERVER['REMOTE_USER'];
    }
    if ($usernameHeader !== '') {
        $_SESSION['mh_auth_user'] = $usernameHeader;
        mh_load_biometrics_user($usernameHeader);
    }
}

// Define authentication variables from session
$isLoggedIn = isset($_SESSION['mh_auth_user']) && is_string($_SESSION['mh_auth_user']) && trim($_SESSION['mh_auth_user']) !== '';
$userRole = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
$bioRoleError = null;

// Attempt to reload role if logged in but role is missing
if ($isLoggedIn && $userRole === '') {
    if (function_exists('mh_load_biometrics_user')) {
        mh_load_biometrics_user($_SESSION['mh_auth_user']);
        // Refresh variables
        $userRole = isset($_SESSION['mh_auth_role']) ? trim($_SESSION['mh_auth_role']) : '';
    }
}

$bioRoleResolved = false;
if ($isLoggedIn) {
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (function_exists('database_getConnectionById')) {
            $bioDb = database_getConnectionById('biometrics');
            $roleCandidates = ['role', 'roles'];
            $username = trim((string)($_SESSION['mh_auth_user'] ?? ''));
            $userId = isset($_SESSION['mh_user_internal_id']) ? (string)$_SESSION['mh_user_internal_id'] : '';

            $lookups = [];
            if ($username !== '') {
                $lookups[] = ['col' => 'username', 'val' => $username];
                $lookups[] = ['col' => 'tenant_id', 'val' => 'user:' . $username];
                $lookups[] = ['col' => 'persona_name', 'val' => $username];
            }
            if ($userId !== '') {
                $lookups[] = ['col' => 'id', 'val' => $userId];
            }

            $rawRole = '';
            foreach ($lookups as $lookup) {
                foreach ($roleCandidates as $roleCol) {
                    try {
                        $stmt = $bioDb->prepare("SELECT {$roleCol} AS r, username FROM users WHERE {$lookup['col']} = ? LIMIT 1");
                        $stmt->execute([$lookup['val']]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row && array_key_exists('r', $row)) {
                            $rawRole = (string)$row['r'];
                            if (!empty($row['username']) && !$isLoggedIn) {
                                $_SESSION['mh_auth_user'] = (string)$row['username'];
                                $isLoggedIn = true;
                            }
                            break 2;
                        }
                    } catch (Throwable $e) {
                        if ($bioRoleError === null) {
                            $bioRoleError = $e->getMessage();
                        }
                    }
                }
            }
            if ($rawRole !== '') {
                $_SESSION['mh_auth_role'] = $rawRole;
                $userRole = trim((string)$rawRole);
                $bioRoleResolved = true;
            }
        }
    } catch (Throwable $e) {
        $bioRoleError = $e->getMessage();
    }
}

$isKripzMaster = ($isLoggedIn && $userRole !== '' && stripos($userRole, 'kripzmaster') !== false);

// Handle AJAX requests - ensure clean JSON output
if ((PHP_SAPI === 'cli' && (isset($argv) && count($argv) > 1)) || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']))) {
    if (PHP_SAPI === 'cli' && (!isset($_POST['action']) || $_POST['action'] === '')) {
        foreach ($argv as $arg) {
            if (strpos($arg, '--action=') === 0) {
                $_POST['action'] = substr($arg, 9);
                break;
            }
        }
    }
    // Clean any previous output and set JSON headers
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    // SECURITY: Authentication Gate
    // This file is a management interface and should be protected.
    // Allow access if:
    // 1. CLI execution
    // 2. Authenticated User with KripzMasters role
    
    $action = $_POST['action'] ?? '';

    // Define actions that this manager handles
    $managerActions = [
        'load_realms', 'load_menus', 'load_submenus', 
        'save_permission', 'save_user_permissions', 
        'save_user_role', 'save_user_username', 'save_user_pin',
        'load_users', 'migrate_permissions_uid',
        'add_kripz_master', 'load_user_permissions',
        'encrypt_all_pins'
    ];

    // Only enforce permissions if the action is one of ours
    if (in_array($action, $managerActions)) {
        $is_authenticated = false;

        // 1. CLI Access - Always allowed
        if (PHP_SAPI === 'cli') {
            $is_authenticated = true;
        } 
        // 2. Already authenticated via session
        elseif ($isKripzMaster) {
            $is_authenticated = true;
        } 
        // 3. Authenticated User (any role) for read-only load actions
        elseif ($isLoggedIn && in_array($action, ['load_realms', 'load_menus', 'load_submenus'])) {
            $is_authenticated = true;
        } 
        // 4. Manual KripzMaster Addition (special case)
        elseif ($action === 'add_kripz_master') {
             if ($isKripzMaster || PHP_SAPI === 'cli') {
                 $is_authenticated = true;
             }
        }
        
        // 5. Fallback: Force DB Re-check for KripzMaster role
        // This handles cases where session data might be stale or incomplete
        if (!$is_authenticated && $isLoggedIn) {
            $uid = $_SESSION['mh_auth_user'] ?? '';
            
            try {
                if (function_exists('cue_autoload')) {
                    cue_autoload('database');
                }

                if (is_string($uid) && $uid !== '' && function_exists('database_getConnectionById')) {
                    $bioDb = database_getConnectionById('biometrics');
                    $rawRole = '';
                    $keyCandidates = ['username', 'user_id', 'uid'];
                    $roleCandidates = ['role', 'roles'];
                    foreach ($keyCandidates as $keyCol) {
                        foreach ($roleCandidates as $roleCol) {
                            try {
                                $stmt = $bioDb->prepare("SELECT {$roleCol} AS r FROM users WHERE {$keyCol} = ? LIMIT 1");
                                $stmt->execute([$uid]);
                                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($row && isset($row['r'])) {
                                    $rawRole = (string)$row['r'];
                                    break 2;
                                }
                            } catch (Throwable $e) {
                            }
                        }
                    }
                    if ($rawRole !== '' && stripos($rawRole, 'KripzMasters') !== false) {
                        $is_authenticated = true;
                        $isKripzMaster = true;
                        $_SESSION['mh_auth_role'] = 'KripzMasters';
                    }
                }
            } catch (Throwable $e) {
            }
        }

        if (!$is_authenticated) {
            $debugRole = $userRole ?: 'none';
            $debugLogin = $isLoggedIn ? 'yes' : 'no';
            $debugUser = $_SESSION['mh_auth_user'] ?? 'none';
            $debugBio = $bioRoleResolved ? 'yes' : 'no';
            $debugBioErr = $bioRoleError ? $bioRoleError : 'none';
            $logMsg = "Access denied: KripzMasters only. (Role: $debugRole, LoggedIn: $debugLogin, User: $debugUser, BioRoleResolved: $debugBio, BioErr: $debugBioErr)";
            
            // Allow basic read-only access to logged-in users to fix navigator/graph assignment
            if ($isLoggedIn && $action === 'load_realms') {
                 // We don't return here, we just fall through to the logic below, 
                 // BUT we must not exit with error.
                 // So we set a flag or just proceed?
                 // The code below expects to proceed if not exited.
                 // BUT wait, we are inside `if (!$is_authenticated)`.
                 // So we must NOT exit here if we want to allow it.
            } else {
                echo json_encode(['success' => false, 'error' => $logMsg]);
                exit;
            }
        }
    } else {
        if ($action !== '') {
             return; 
        }
    }
    
    function mpm_getDbByIdOrName(string $configIdOrName) {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if ($configIdOrName === 'onemeta_ldap') {
            if (function_exists('database_getContextAwareConnection')) {
                return database_getContextAwareConnection();
            }
            return cue_autoload('database')->getContextAwareConnection();
        }
        if (function_exists('database_getConnectionById')) {
            return database_getConnectionById($configIdOrName);
        }
        if (function_exists('cue_autoload')) {
            return cue_autoload('database')->getConnectionById($configIdOrName);
        }
        return null;
    }

    function mpm_tableHasColumn(PDO $db, string $table, string $column): bool {
        try {
            $tableSafe = str_replace('`', '``', $table);
            $colQuoted = $db->quote($column);
            $sql = "SHOW COLUMNS FROM `{$tableSafe}` LIKE {$colQuoted}";
            $stmt = $db->query($sql);
            if (!$stmt) {
                return false;
            }
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return false;
        }
    }

    function mpm_pickFirstExistingColumn(PDO $db, string $table, array $candidates): ?string {
        foreach ($candidates as $col) {
            if (is_string($col) && $col !== '' && mpm_tableHasColumn($db, $table, $col)) {
                return $col;
            }
        }
        return null;
    }

    switch ($_POST['action']) {
        case 'add_kripz_master':
            try {
                // Manual KripzMaster Addition using register.php process logic
                $username = trim($_POST['username'] ?? '');
                $displayName = trim($_POST['name'] ?? '');
                $personaName = trim($_POST['persona_name'] ?? '');
                $pin = trim($_POST['pin'] ?? '');

                if ($username === '' || $displayName === '' || $personaName === '' || $pin === '') {
                    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                    exit;
                }
                if (function_exists('mh_username_is_reserved_prefix') && mh_username_is_reserved_prefix($username)) {
                    echo json_encode(['success' => false, 'error' => 'Username is invalid. Please choose your own unique username.']);
                    exit;
                }

                // Load Auth Classes
                require_once dirname(dirname(dirname(__DIR__))) . '/auth/auth_classes.php';

                // 1. Set PIN
                $pinBackup = new MetaPinBackup();
                $pinBackup->setPinForUser($username, $pin);

                $db = mpm_getDbByIdOrName('biometrics');

                if (!$db) {
                    throw new Exception("Database connection failed");
                }
                if (function_exists('mh_ensure_user_real_name_schema')) {
                    mh_ensure_user_real_name_schema($db);
                }

                // Check if user exists
                $stmt = $db->prepare("SELECT uid FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    // Update role to KripzMasters if exists
                    $stmt = $db->prepare("UPDATE users SET role = 'KripzMasters' WHERE username = ?");
                    $stmt->execute([$username]);
                    echo json_encode(['success' => true, 'message' => 'User updated to KripzMasters']);
                } else {
                    // Create new user
                    $tenantId = 'user:' . $username;
                    $parts = preg_split('/\s+/', $displayName);
                    $parts = array_values(array_filter(array_map('trim', is_array($parts) ? $parts : []), fn($p) => $p !== ''));
                    $firstName = count($parts) >= 2 ? (string)$parts[0] : $displayName;
                    $surname = count($parts) >= 2 ? (string)$parts[count($parts) - 1] : $displayName;
                    if (function_exists('mh_validate_real_first_and_surname_strict')) {
                        mh_validate_real_first_and_surname_strict($firstName, $surname);
                    }
                    $pdoReg = mh_persona_registry_pdo();
                    if (!mh_persona_registry_claim($pdoReg, $username, $personaName)) {
                        throw new Exception('Persona name is already taken');
                    }
                    try {
                        $stmt = $db->prepare("INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, tenant_id, role, created_at) VALUES (?, ?, ?, ?, ?, ?, 'KripzMasters', NOW())");
                        $stmt->execute([$username, $displayName, $firstName, $surname, $personaName, $tenantId]);
                    } catch (Throwable $e) {
                        mh_persona_registry_release($pdoReg, $username, $personaName);
                        throw $e;
                    }
                    $dbConfigId = mh_resolve_tenant_db_config_id($tenantId);
                    if (!is_string($dbConfigId) || $dbConfigId === '') {
                        mh_provision_tenant_storage($tenantId);
                        $prov = mh_provision_tenant_database($tenantId);
                        $dbConfigId = is_array($prov) ? (string)($prov['db_config_id'] ?? '') : '';
                    }
                    if ($dbConfigId !== '') {
                        $pdoTenant = database_getConnectionById($dbConfigId);
                        if ($pdoTenant instanceof PDO) {
                        }
                    }
                    echo json_encode(['success' => true, 'message' => 'KripzMaster created successfully']);
                }
            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'migrate_permissions_uid':
            try {
                $dbTarget = mpm_getDbByIdOrName('onemeta_ldap');
                if (!$dbTarget) {
                    echo json_encode(['success' => false, 'error' => 'Database connection unavailable']);
                    exit;
                }
                $ok = 0; $errors = [];
                $r1 = function_exists('cueExecuteQuery') ? cueExecuteQuery($dbTarget, "ALTER TABLE page_permissions MODIFY COLUMN user_id VARCHAR(255) NOT NULL") : null;
                if ($r1 && isset($r1['success']) && $r1['success']) { $ok++; } else { $errors[] = 'ALTER failed'; }
                $r2 = function_exists('cueExecuteQuery') ? cueExecuteQuery($dbTarget, "UPDATE page_permissions p JOIN users u ON p.user_id = u.id SET p.user_id = u.uid") : null;
                if ($r2 && isset($r2['success']) && $r2['success']) { $ok++; } else { $errors[] = 'UPDATE failed'; }
                $r3 = function_exists('cueExecuteQuery') ? cueExecuteQuery($dbTarget, "CREATE INDEX idx_page_permissions_user ON page_permissions(user_id)") : null;
                if ($r3 && isset($r3['success']) && $r3['success']) { $ok++; }
                echo json_encode(['success' => true, 'message' => 'Migration completed', 'ops_ok' => $ok, 'errors' => $errors]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        case 'load_users':
            try {
                $db = mpm_getDbByIdOrName('biometrics');
                if (!$db) {
                    throw new Exception("Database connection failed");
                }
                if (function_exists('mh_ensure_user_real_name_schema')) {
                    mh_ensure_user_real_name_schema($db);
                }
                try {
                    $stmt = $db->prepare("SELECT username as uid, name, real_first_name, real_last_name, role as roles, username, pin_hash FROM users ORDER BY name, username");
                    $stmt->execute();
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    $stmt = $db->prepare("SELECT uid, cn as name, COALESCE(roles, '') as roles, COALESCE(username, uid) as username FROM users ORDER BY cn, uid");
                    $stmt->execute();
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $passkeyAuth = null;
                try {
                    $passkeyAuth = new MetaPasskeyAuth();
                } catch (Throwable $e) {
                    $passkeyAuth = null;
                }

                $filtered = [];
                foreach ($users as $u) {
                    $username = isset($u['username']) ? trim((string)$u['username']) : '';
                    $uid = isset($u['uid']) ? trim((string)$u['uid']) : $username;
                    $key = $username !== '' ? $username : $uid;
                    if ($key === '') {
                        continue;
                    }

                    $hasPin = false;
                    if (isset($u['pin_hash']) && is_string($u['pin_hash']) && trim($u['pin_hash']) !== '') {
                        $hasPin = true;
                    }

                    $hasPasskey = false;
                    if ($passkeyAuth) {
                        try {
                            $hasPasskey = (bool)$passkeyAuth->hasUserPasskeys($key);
                        } catch (Throwable $e) {
                            $hasPasskey = false;
                        }
                    }

                    if (!$hasPin && !$hasPasskey) {
                        continue;
                    }

                    $u['uid'] = $key;
                    $u['username'] = $username !== '' ? $username : $key;
                    $u['has_pin'] = $hasPin;
                    $u['has_passkey'] = $hasPasskey;
                    unset($u['pin_hash']);
                    $roleRaw = isset($u['roles']) ? trim((string)$u['roles']) : '';
                    $roleNorm = strtolower($roleRaw);
                    if ($roleNorm !== '' && stripos($roleNorm, 'kripzmaster') !== false) {
                        $u['roles'] = 'kripzmasters';
                    } else {
                        $u['roles'] = 'users';
                    }
                    $filtered[] = $u;
                }
                
                echo json_encode(['success' => true, 'users' => $filtered]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'load_realms':
            try {
                $db = mpm_getDbByIdOrName('onemeta_ldap');
                
                $stmt = $db->prepare("SELECT id, name, description, icon FROM realms ORDER BY priority ASC, order_index ASC, name ASC");
                $stmt->execute();
                $allRealms = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Filter realms based on role
                $filteredRealms = [];
                // Allowed realms for standard users
                $allowedRealms = ['Hub'];
                
                foreach ($allRealms as $realm) {
                    if ($isKripzMaster) {
                        $filteredRealms[] = $realm;
                    } else {
                        // Standard users see only allowed realms
                        foreach ($allowedRealms as $allowed) {
                            if (strcasecmp($realm['name'], $allowed) === 0 || strcasecmp($realm['id'], $allowed) === 0) {
                                $filteredRealms[] = $realm;
                                break;
                            }
                        }
                    }
                }
                
                echo json_encode(['success' => true, 'realms' => $filteredRealms]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'load_menus':
            try {
                $db = mpm_getDbByIdOrName('onemeta_ldap');
                
                $realmId = $_POST['realm_id'] ?? null;
                
                if ($realmId) {
                    $stmt = $db->prepare("SELECT id, name, title, url, realm_id, icon FROM menus WHERE realm_id = ? AND status = 'active' ORDER BY order_index ASC, name ASC");
                    $stmt->execute([$realmId]);
                } else {
                    $stmt = $db->prepare("SELECT id, name, title, url, realm_id, icon FROM menus WHERE status = 'active' ORDER BY realm_id, order_index ASC, name ASC");
                    $stmt->execute();
                }
                
                $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'menus' => $menus]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'load_submenus':
            try {
                $db = mpm_getDbByIdOrName('onemeta_ldap');
                $menuId = $_POST['menu_id'] ?? null;
                $menuIdsParam = $_POST['menu_ids'] ?? null;
                $menuIds = [];
                if ($menuIdsParam) {
                    $decoded = json_decode($menuIdsParam, true);
                    if (is_array($decoded)) {
                        $menuIds = array_values(array_filter($decoded, function($v){ return $v !== null && $v !== ''; }));
                    }
                }
                if (!empty($menuIds)) {
                    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
                    $sql = "SELECT s.id, s.name, s.name AS title, s.url, s.menu_id AS parent_id, m.realm_id FROM submenus s JOIN menus m ON s.menu_id = m.id WHERE s.status = 'active' AND s.menu_id IN ($placeholders) ORDER BY s.menu_id, s.name ASC";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($menuIds);
                    $submenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($submenus)) {
                        $submenus = [];
                        foreach ($menuIds as $mid) {
                            $midStr = (string)$mid;
                            if ($midStr === '') continue;
                            $submenus[] = ['id' => $midStr . '_settings', 'name' => $midStr . ' Settings', 'title' => $midStr . ' Settings', 'parent_id' => $midStr, 'realm_id' => null, 'url' => ''];
                            $submenus[] = ['id' => $midStr . '_view', 'name' => $midStr . ' View', 'title' => $midStr . ' View', 'parent_id' => $midStr, 'realm_id' => null, 'url' => ''];
                        }
                    }
                } else {
                    try {
                        if ($menuId) {
                            $sql = "SELECT s.id, s.name, s.name AS title, s.url, s.menu_id AS parent_id, m.realm_id FROM submenus s JOIN menus m ON s.menu_id = m.id WHERE s.status = 'active' AND s.menu_id = ? ORDER BY s.name ASC";
                            $stmt = $db->prepare($sql);
                            $stmt->execute([$menuId]);
                        } else {
                            $sql = "SELECT s.id, s.name, s.name AS title, s.url, s.menu_id AS parent_id, m.realm_id FROM submenus s JOIN menus m ON s.menu_id = m.id WHERE s.status = 'active' ORDER BY s.menu_id, s.name ASC";
                            $stmt = $db->prepare($sql);
                            $stmt->execute();
                        }
                        $submenus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $stmt = $db->prepare("SELECT id, name, title, url, realm_id FROM menus WHERE status = 'active' ORDER BY name ASC");
                        $stmt->execute();
                        $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($menuIds)) {
                            $menus = array_values(array_filter($menus, function($m) use ($menuIds){ return in_array($m['id'], $menuIds); }));
                        } elseif ($menuId) {
                            $menus = array_values(array_filter($menus, function($m) use ($menuId){ return (string)$m['id'] === (string)$menuId; }));
                        }
                        $submenus = [];
                        foreach ($menus as $menu) {
                            $submenus[] = ['id' => $menu['id'] . '_settings', 'name' => ($menu['name'] ?? '') . ' Settings', 'title' => ($menu['title'] ?? '') . ' Settings', 'parent_id' => $menu['id'], 'realm_id' => $menu['realm_id']];
                            $submenus[] = ['id' => $menu['id'] . '_view', 'name' => ($menu['name'] ?? '') . ' View', 'title' => ($menu['title'] ?? '') . ' View', 'parent_id' => $menu['id'], 'realm_id' => $menu['realm_id']];
                        }
                    }
                }
                echo json_encode(['success' => true, 'submenus' => $submenus]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'load_user_permissions':
            try {
                $userUid = (string)($_POST['user_id'] ?? '');
                $dbBio = mpm_getDbByIdOrName('biometrics');
                if (!$dbBio) {
                    throw new Exception('Biometrics database connection failed');
                }

                if ($userUid === '') {
                    throw new Exception('Invalid or missing user identifier');
                }

                $userKeyCol = mpm_pickFirstExistingColumn($dbBio, 'users', ['username', 'uid', 'user_id', 'id']);
                if ($userKeyCol === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }

                $roleCol = mpm_pickFirstExistingColumn($dbBio, 'users', ['role', 'roles']);
                $permCol = mpm_pickFirstExistingColumn($dbBio, 'users', ['permissions', 'permission', 'perms', 'menu_permissions']);

                $selectCols = [];
                if ($roleCol !== null) { $selectCols[] = "{$roleCol} AS role_val"; }
                if ($permCol !== null) { $selectCols[] = "{$permCol} AS perms_val"; }
                if (empty($selectCols)) {
                    $selectCols[] = "'' AS role_val";
                    $selectCols[] = "'' AS perms_val";
                }

                $stmt = $dbBio->prepare("SELECT " . implode(', ', $selectCols) . " FROM users WHERE {$userKeyCol} = ? LIMIT 1");
                $stmt->execute([$userUid]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $roleValue = (string)($user['role_val'] ?? '');
                $roleNorm = strtolower(trim($roleValue));
                $isKripz = ($roleNorm !== '' && stripos($roleNorm, 'kripzmaster') !== false);

                $dbMain = mpm_getDbByIdOrName('onemeta_ldap');
                if (!$dbMain) {
                    throw new Exception('Navigator database connection failed');
                }

                $directoryRoleRaw = '';
                try {
                    $stmtDir = $dbMain->prepare("SELECT roles FROM users WHERE username = ? LIMIT 1");
                    $stmtDir->execute([$userUid]);
                    $rowDir = $stmtDir->fetch(PDO::FETCH_ASSOC);
                    $directoryRoleRaw = (string)($rowDir['roles'] ?? '');
                } catch (Throwable $e) {
                    $directoryRoleRaw = '';
                }
                $directoryRoleNorm = strtolower(trim($directoryRoleRaw));
                $directorySaysKripz = ($directoryRoleNorm !== '' && stripos($directoryRoleNorm, 'kripzmaster') !== false);
                $isKripz = $directorySaysKripz;

                if ($isKripz) {
                    $rs = $dbMain->query("SELECT id FROM realms");
                    $ms = $dbMain->query("SELECT id FROM menus WHERE status = 'active'");
                    $ss = $dbMain->query("SELECT id FROM submenus WHERE status = 'active'");

                    $userPermissions = [
                        'realms' => array_map('strval', array_column($rs->fetchAll(PDO::FETCH_ASSOC), 'id')),
                        'menus' => array_map('strval', array_column($ms->fetchAll(PDO::FETCH_ASSOC), 'id')),
                        'submenus' => array_map('strval', array_column($ss->fetchAll(PDO::FETCH_ASSOC), 'id'))
                    ];
                    echo json_encode(['success' => true, 'permissions' => $userPermissions, 'override' => 'kripzmasters']);
                    exit;
                }

                $rawPerms = isset($user['perms_val']) ? (string)$user['perms_val'] : '';
                if ($rawPerms !== '') {
                    $decoded = json_decode($rawPerms, true);
                    if (is_array($decoded)) {
                        $realms = isset($decoded['realms']) && is_array($decoded['realms']) ? array_values(array_unique(array_map('strval', $decoded['realms']))) : [];
                        $menus = isset($decoded['menus']) && is_array($decoded['menus']) ? array_values(array_unique(array_map('strval', $decoded['menus']))) : [];
                        $submenus = isset($decoded['submenus']) && is_array($decoded['submenus']) ? array_values(array_unique(array_map('strval', $decoded['submenus']))) : [];
                        if (!in_array('hub', $realms, true)) {
                            array_unshift($realms, 'hub');
                        }
                        echo json_encode(['success' => true, 'permissions' => ['realms' => $realms, 'menus' => $menus, 'submenus' => $submenus], 'override' => 'stored']);
                        exit;
                    }
                }

                $hubId = 'hub';
                $stmt = $dbMain->prepare("SELECT id FROM menus WHERE realm_id = ? AND status = 'active'");
                $stmt->execute([$hubId]);
                $menuIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $menuIds = array_map('strval', $menuIds ?: []);

                $submenuIds = [];
                if (!empty($menuIds)) {
                    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
                    $stmt = $dbMain->prepare("SELECT id FROM submenus WHERE menu_id IN ($placeholders) AND status = 'active'");
                    $stmt->execute($menuIds);
                    $submenuIds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                }

                echo json_encode(['success' => true, 'permissions' => ['realms' => ['hub'], 'menus' => $menuIds, 'submenus' => $submenuIds], 'override' => 'users']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'save_user_permissions':
            try {
                $db = null;
                if (function_exists('cue_autoload')) {
                    cue_autoload('database');
                }
                if (function_exists('database_getConnectionById')) {
                    $db = database_getConnectionById('biometrics');
                } else {
                    $db = cue_autoload('database')->getConnectionById('biometrics');
                }
                if (!$db) {
                    throw new Exception('Biometrics database connection failed');
                }

                $userUid = $_POST['user_id'];
                if (empty($userUid)) {
                    throw new Exception('Invalid or missing user identifier');
                }

                $userKeyColRole = mpm_pickFirstExistingColumn($db, 'users', ['username', 'uid', 'user_id', 'id']);
                if ($userKeyColRole === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }
                $roleCol = mpm_pickFirstExistingColumn($db, 'users', ['role', 'roles']);
                $roleValue = '';
                if ($roleCol !== null) {
                    $stmt = $db->prepare("SELECT {$roleCol} AS r FROM users WHERE {$userKeyColRole} = ? LIMIT 1");
                    $stmt->execute([$userUid]);
                    $roleValue = (string)$stmt->fetchColumn();
                }
                $isKripz = ($roleValue !== '' && stripos($roleValue, 'kripzmaster') !== false);

                $dbMain = mpm_getDbByIdOrName('onemeta_ldap');
                if (!$dbMain) {
                    throw new Exception('Navigator database connection failed');
                }

                if ($isKripz) {
                    $rs = $dbMain->query("SELECT id FROM realms");
                    $ms = $dbMain->query("SELECT id FROM menus WHERE status = 'active'");
                    $ss = $dbMain->query("SELECT id FROM submenus WHERE status = 'active'");
                    $permissionsJson = json_encode([
                        'realms' => array_map('strval', array_column($rs->fetchAll(PDO::FETCH_ASSOC), 'id')),
                        'menus' => array_map('strval', array_column($ms->fetchAll(PDO::FETCH_ASSOC), 'id')),
                        'submenus' => array_map('strval', array_column($ss->fetchAll(PDO::FETCH_ASSOC), 'id'))
                    ]);
                } else {
                    $hubId = 'hub';
                    $stmt = $dbMain->prepare("SELECT id FROM menus WHERE realm_id = ? AND status = 'active'");
                    $stmt->execute([$hubId]);
                    $menuIds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

                    $submenuIds = [];
                    if (!empty($menuIds)) {
                        $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
                        $stmt = $dbMain->prepare("SELECT id FROM submenus WHERE menu_id IN ($placeholders) AND status = 'active'");
                        $stmt->execute($menuIds);
                        $submenuIds = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                    }

                    $permissionsJson = json_encode([
                        'realms' => ['hub'],
                        'menus' => $menuIds,
                        'submenus' => $submenuIds
                    ]);
                }
                
                // Update Biometrics Users
                $userKeyCol = mpm_pickFirstExistingColumn($db, 'users', ['username', 'uid', 'user_id', 'id']);
                if ($userKeyCol === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }
                $userPermCol = mpm_pickFirstExistingColumn($db, 'users', ['permissions', 'permission', 'perms', 'menu_permissions']);
                if ($userPermCol === null) {
                    try {
                        $db->exec("ALTER TABLE users ADD COLUMN permissions LONGTEXT NULL");
                        $userPermCol = 'permissions';
                    } catch (Throwable $e) {
                        throw new Exception('Unsupported users schema: missing permissions column');
                    }
                }
                $stmt = $db->prepare("UPDATE users SET {$userPermCol} = ? WHERE {$userKeyCol} = ?");
                $stmt->execute([$permissionsJson, $userUid]);
                
                echo json_encode(['success' => true, 'message' => 'Permissions saved successfully to Biometrics DB']);

            } catch (Throwable $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'save_user_role':
            try {
                $db = mpm_getDbByIdOrName('biometrics');
                $userId = (string)($_POST['user_id'] ?? '');
                $role = (string)($_POST['role'] ?? '');
                if (empty($userId) || empty($role)) {
                    throw new Exception('User ID and role are required');
                }
                $roleNorm = strtolower(trim($role));
                if ($roleNorm === 'kripzmasters' || $roleNorm === 'kripzmaster') {
                    $role = 'KripzMasters';
                } elseif ($roleNorm === 'users' || $roleNorm === 'user') {
                    $role = 'Users';
                }
                if (!$db) {
                    throw new Exception('Database connection failed');
                }
                $keyCol = mpm_pickFirstExistingColumn($db, 'users', ['username', 'uid']);
                if ($keyCol === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }
                $roleCol = mpm_pickFirstExistingColumn($db, 'users', ['role', 'roles']);
                if ($roleCol === null) {
                    throw new Exception('Unsupported users schema: missing role/roles');
                }
                $stmt = $db->prepare("UPDATE users SET {$roleCol} = ? WHERE {$keyCol} = ?");
                $result = $stmt->execute([$role, $userId]);
                if ($result) {
                    try {
                        $dirDb = mpm_getDbByIdOrName('onemeta_ldap');
                        if ($dirDb) {
                            $dirRole = 'users';
                            $rnorm = strtolower(trim($role));
                            if ($rnorm !== '' && stripos($rnorm, 'kripzmaster') !== false) {
                                $dirRole = 'kripzmasters';
                            }
                            $stmtDir = $dirDb->prepare("UPDATE users SET roles = ? WHERE username = ?");
                            $stmtDir->execute([$dirRole, $userId]);
                        }
                    } catch (Throwable $e) {
                    }
                    echo json_encode(['success' => true, 'message' => 'User role updated successfully']);
                } else {
                    throw new Exception('Failed to update user role in database');
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        case 'save_user_username':
            try {
                $db = mpm_getDbByIdOrName('biometrics');
                $userId = (string)($_POST['user_id'] ?? '');
                $username = (string)($_POST['username'] ?? '');
                if ($userId === '' || $username === '') {
                    throw new Exception('User ID and username are required');
                }
                if (!$db) {
                    throw new Exception('Database connection failed');
                }
                $keyCol = mpm_pickFirstExistingColumn($db, 'users', ['username', 'uid']);
                if ($keyCol === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }
                if (!mpm_tableHasColumn($db, 'users', 'username')) {
                    throw new Exception('Unsupported users schema: missing username column');
                }
                $stmtOld = $db->prepare("SELECT username FROM users WHERE {$keyCol} = ? LIMIT 1");
                $stmtOld->execute([$userId]);
                $oldUsername = trim((string)$stmtOld->fetchColumn());
                $stmt = $db->prepare("UPDATE users SET username = ? WHERE {$keyCol} = ?");
                $result = $stmt->execute([$username, $userId]);
                if ($result) {
                    try {
                        if ($oldUsername !== '' && $oldUsername !== $username) {
                            $stmtPk = $db->query("SHOW TABLES LIKE 'webauthn_credentials'");
                            $hasPk = $stmtPk && $stmtPk->fetch(PDO::FETCH_NUM);
                            if ($hasPk) {
                                $db->prepare("UPDATE webauthn_credentials SET user_id = ? WHERE user_id = ?")->execute([$username, $oldUsername]);
                            }
                        }
                    } catch (Throwable $e) {
                    }
                    echo json_encode(['success' => true, 'message' => 'Username updated successfully']);
                } else {
                    throw new Exception('Failed to update username in database');
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        case 'save_user_pin':
            try {
                $db = mpm_getDbByIdOrName('biometrics');
                $userId = $_POST['user_id'] ?? '';
                $pin = $_POST['pin'] ?? '';
                if ($userId === '' || $pin === '') {
                    throw new Exception('User ID and PIN are required');
                }
                if (!$db) {
                    throw new Exception('Database connection failed');
                }
                $keyCol = mpm_pickFirstExistingColumn($db, 'users', ['username', 'uid']);
                if ($keyCol === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }
                if (!class_exists('MetaPinBackup')) {
                    throw new Exception('PIN system unavailable');
                }
                $pinBackup = new MetaPinBackup();
                $pinBackup->setPinForUser((string)$userId, (string)$pin);
                try {
                    if (mpm_tableHasColumn($db, 'users', 'pin')) {
                        $db->prepare("UPDATE users SET pin = NULL WHERE {$keyCol} = ?")->execute([(string)$userId]);
                    }
                } catch (Throwable $e) {
                }
                echo json_encode(['success' => true, 'message' => 'PIN saved securely']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        case 'encrypt_all_pins':
            try {
                $db = mpm_getDbByIdOrName('biometrics');
                $security = cue_autoload('security');
                if (!function_exists('getEncryptionKey') || !$security) {
                    throw new Exception('Encryption system unavailable');
                }
                if (!$db) {
                    throw new Exception('Database connection failed');
                }
                if (!mpm_tableHasColumn($db, 'users', 'pin')) {
                    throw new Exception('PIN storage not supported in this users schema');
                }
                $keyCol = mpm_pickFirstExistingColumn($db, 'users', ['uid', 'username']);
                if ($keyCol === null) {
                    throw new Exception('Unsupported users schema: missing username/uid');
                }
                $key = getEncryptionKey();
                $stmt = $db->prepare("SELECT {$keyCol} as uid, pin FROM users");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $scanned = 0; $updated = 0; $skipped = 0; $errors = 0;
                foreach ($rows as $row) {
                    $scanned++;
                    $uid = $row['uid'];
                    $pinVal = (string)($row['pin'] ?? '');
                    if ($pinVal === '') { $skipped++; continue; }
                    $dec = '';
                    try { $dec = $security->decryptValue($pinVal, $key); } catch (Exception $de) { $dec = ''; }
                    if ($dec !== '') { $skipped++; continue; }
                    try {
                        $enc = $security->encryptValue($pinVal, $key);
                        $upd = $db->prepare("UPDATE users SET pin = ? WHERE {$keyCol} = ?");
                        if ($upd->execute([$enc, $uid])) { $updated++; } else { $errors++; }
                    } catch (Exception $ee) { $errors++; }
                }
                echo json_encode(['success' => true, 'stats' => ['scanned' => $scanned, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit;
    }
}

/**
 * Get available user roles/groups
 */
function getAvailableUserRoles() {
    return [
        'kripzmasters' => ['name' => 'KripzMasters', 'description' => 'Complete and full access at all times'],
        'users' => ['name' => 'Users', 'description' => 'Default access to /hub']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>User Permission Manager</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/templates/widgets/icons/icon-widget.css">
    <script src="/templates/widgets/icons/icon-widget.js"></script>
    <?php echo includeNoticesWidget(); ?>
    <style>
        body.mpm-page main.main-content,
        body.mpm-page main.main-content * {
            box-sizing: border-box;
        }

        body.mpm-page main.main-content {
            display: flex;
            padding: 40px 0 0;
        }

        body.mpm-page main.main-content .container {
            max-width: var(--layout-max_width, 1400px);
            margin: 0 auto;
            padding: 0 20px;
            flex: 1;
            display: flex;
        }

        body.mpm-page main.main-content .mpm-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 26px;
            border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.25);
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: var(--shadow-card, 0 8px 32px rgba(0, 0, 0, 0.3));
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        body.mpm-page main.main-content #security-utilities {
            overflow: hidden;
        }
        body.mpm-page main.main-content #security-utilities .btn {
            margin: 0;
            width: 100%;
        }
        body.mpm-page main.main-content #security-utilities > div {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        
        body.mpm-page main.main-content .mpm-shell h1,
        body.mpm-page main.main-content .mpm-shell h2,
        body.mpm-page main.main-content .mpm-shell h3 {
            color: var(--theme-primary, #00ffff);
            margin-bottom: 18px;
        }
        
        /* Button Styles */
        body.mpm-page main.main-content .mpm-shell .btn {
            background: linear-gradient(45deg, #00ffff, #0080ff);
            color: #1a1a2e;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 5px;
            box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3);
        }
        
        body.mpm-page main.main-content .mpm-shell .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 255, 255, 0.4);
        }
        
        body.mpm-page main.main-content .mpm-shell .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Form Styles */
        body.mpm-page main.main-content .mpm-shell .form-group {
            margin-bottom: 25px;
        }
        
        body.mpm-page main.main-content .mpm-shell label {
            display: block;
            margin-bottom: 8px;
            color: #00ffff;
            font-weight: bold;
            font-size: 16px;
        }
        
        body.mpm-page main.main-content .mpm-shell select {
            width: 100%;
            padding: 12px;
            background: rgba(0, 0, 0, 0.3);
            border: 2px solid #00ffff;
            border-radius: 8px;
            color: #ffffff;
            font-size: 16px;
        }
        
        body.mpm-page main.main-content .mpm-shell select option {
            background: #1a1a2e;
            color: #ffffff;
        }
        
        /* Layout */
        body.mpm-page main.main-content .mpm-shell .main-layout {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 24px;
            margin-top: 22px;
            flex: 1;
        }
        
        body.mpm-page main.main-content .mpm-shell .user-selection {
            background: rgba(0, 0, 0, 0.25);
            padding: 22px;
            border-radius: 14px;
            border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.25);
            height: fit-content;
        }
        
        body.mpm-page main.main-content .mpm-shell .permissions-panel {
            background: rgba(0, 0, 0, 0.25);
            padding: 22px;
            border-radius: 14px;
            border: 1px solid rgba(var(--theme-primary-rgb, 0, 255, 255), 0.25);
        }
        
        /* Permission Sections */
        body.mpm-page main.main-content .mpm-shell .permission-section {
            margin-bottom: 40px;
            padding: 20px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            border: 1px solid rgba(0, 255, 255, 0.1);
        }
        
        body.mpm-page main.main-content .mpm-shell .permission-section h3 {
            margin-bottom: 15px;
            color: #00ffff;
            font-size: 18px;
        }
        
        /* Toggle Switch Styles */
        body.mpm-page main.main-content .mpm-shell .toggle-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            margin-bottom: 8px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(0, 255, 255, 0.2);
            transition: all 0.3s ease;
        }
        
        body.mpm-page main.main-content .mpm-shell .toggle-item:hover {
            background: rgba(0, 255, 255, 0.1);
        }
        
        body.mpm-page main.main-content .mpm-shell .toggle-info {
            flex: 1;
        }
        
        body.mpm-page main.main-content .mpm-shell .toggle-name {
            font-weight: bold;
            color: #ffffff;
            margin-bottom: 4px;
        }
        
        body.mpm-page main.main-content .mpm-shell .toggle-description {
            font-size: 12px;
            color: #aaaaaa;
        }
        
        /* Switch Component */
        body.mpm-page main.main-content .mpm-shell .switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        
        body.mpm-page main.main-content .mpm-shell .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        body.mpm-page main.main-content .mpm-shell .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #333;
            transition: .4s;
            border-radius: 34px;
        }
        
        body.mpm-page main.main-content .mpm-shell .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #00ffff;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        /* Status Messages */
        .status-message {
            padding: 12px 20px;
            border-radius: 8px;
            margin: 15px 0;
            font-weight: bold;
        }
        
        .status-success {
            background: rgba(46, 204, 113, 0.2);
            border: 1px solid #2ecc71;
            color: #2ecc71;
        }
        
        .status-error {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid #e74c3c;
            color: #e74c3c;
        }
        
        .status-info {
            background: rgba(52, 152, 219, 0.2);
            border: 1px solid #3498db;
            color: #3498db;
        }
        
        /* Loading States */
        .loading {
            text-align: center;
            padding: 20px;
            color: #00ffff;
        }
        
        .loading::after {
            content: '';
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #00ffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
            margin-left: 10px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Select All Controls */
        .select-all-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px 15px;
            background: rgba(0, 255, 255, 0.05);
            border-radius: 6px;
            border: 1px solid rgba(0, 255, 255, 0.2);
        }
        
        .select-all-btn {
            background: linear-gradient(45deg, #00ffff, #0080ff);
            color: #1a1a2e;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 12px;
            margin: 0 5px;
        }
        
        .select-all-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 255, 255, 0.3);
        }
        
        .deselect-all-btn {
            background: linear-gradient(45deg, #ff4757, #ff3742);
            color: white;
        }
        
        /* Widget Integration */
        .widget-notice {
            animation: slideInFromTop 0.5s ease-out;
        }
        
        .widget-loader {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
        }
        
        .widget-dragdrop {
            transition: all 0.3s ease;
        }
        
        .widget-autosave {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        
        @keyframes slideInFromTop {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            body.mpm-page main.main-content .mpm-shell .main-layout {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            body.mpm-page main.main-content .mpm-shell {
                padding: 18px;
            }
        }
    </style>
</head>
<body class="mpm-page">
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    
    <main class="main-content">
    <div class="container">
        <div class="mpm-shell">
        <h1><i class="fas fa-user-shield"></i> User Permission Manager</h1>
        
        <!-- Status Messages -->
        <div id="status-messages"></div>
        
        <div class="main-layout">
            <!-- User Selection Panel -->
            <div class="user-selection">
                <h2><i class="fas fa-user"></i> Select User</h2>
                
                <div class="form-group">
                    <label for="user-select">Choose User:</label>
                    <select id="user-select" onchange="selectUser()">
                        <option value="">Loading users...</option>
                    </select>
                </div>
                
                <div id="selected-user-info" style="display: none;">
                    <h3 style="color: #00ffff;">Selected User:</h3>
                    <div id="user-details-display" style="background: rgba(0, 255, 255, 0.1); padding: 15px; border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.3); margin-bottom: 15px;"></div>
                    
                    <div id="role-assignment-section" style="margin-top: 20px; padding: 20px; background: rgba(0, 255, 255, 0.15); border-radius: 10px; border: 2px solid rgba(0, 255, 255, 0.4);">
                        <h4 style="color: #00ffff; margin-bottom: 20px; text-align: center;"><i class="fas fa-user-cog"></i> User Role Assignment</h4>
                        <div style="background: rgba(0, 0, 0, 0.3); padding: 15px; border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.2);">
                            <label for="role-selector-v2" style="color: #00ffff; display: block; margin-bottom: 10px; font-weight: bold;">Select Role:</label>
                            <select id="role-selector-v2" onchange="assignUserRole()" style="width: 100%; padding: 12px; border-radius: 6px; border: 1px solid rgba(0, 255, 255, 0.5); background: rgba(0, 0, 0, 0.7); color: #00ffff; font-size: 14px;">
                                <option value="" style="background: #1a1a2e; color: #00ffff;">Choose a role...</option>
                            </select>
                            <div id="role-assignment-status" style="margin-top: 15px; font-size: 13px; color: #00ffff; text-align: center; min-height: 20px;"></div>
                        </div>
                    </div>
                    
                    <div id="security-utilities" style="margin-top: 15px; padding: 15px; background: rgba(0, 255, 255, 0.08); border-radius: 8px; border: 1px solid rgba(0, 255, 255, 0.3);">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <button onclick="encryptAllPins()" class="btn" style="width: 100%;">
                                <i class="fas fa-lock"></i> Encrypt All PINs
                            </button>
                            <button onclick="copyUidToUsername()" class="btn" style="width: 100%;">
                                <i class="fas fa-user-tag"></i> Copy UID to Username
                            </button>
                        </div>
                        <div id="security-utilities-status" style="margin-top: 10px; font-size: 13px; color: #00ffff; text-align: center; min-height: 20px;"></div>
                    </div>
                    
                    <button id="load-permissions-btn" onclick="loadUserPermissions()" class="btn" style="width: 100%; margin-top: 15px;">
                        <i class="fas fa-cog"></i> Load Permissions
                    </button>
                    
                    <button onclick="savePermissions()" class="btn" style="width: 100%; margin-top: 10px; background: linear-gradient(45deg, #2ecc71, #27ae60);">
                        <i class="fas fa-save"></i> Save Permissions
                    </button>
                </div>
            </div>
            
            <!-- Permissions Panel -->
            <div class="permissions-panel">
                <h2><i class="fas fa-shield-alt"></i> Access Permissions</h2>
                
                <div id="permissions-content">
                    <div class="status-message status-info">
                        <i class="fas fa-info-circle"></i> Please select a user to manage their permissions.
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
    
    <script>
        console.log("SCRIPT LOADED: 2024-12-15 11:52 - SYNTAX FIXED");
        let currentUser = null;
        let allRealms = [];
        let allMenus = [];
        let allSubmenus = [];
        let userPermissions = {
            realms: [],
            menus: [],
            submenus: []
        };
        
        // Available user roles
        const availableRoles = {
            'kripzmasters': { name: 'KripzMasters', description: 'Complete and full access at all times' },
            'users':        { name: 'Users',        description: 'Default access to /hub' }
        };
        
        // Legacy function cleanup - these are no longer needed
        // All role assignment functionality has been moved to the new functions above
        
        // Select All Functions
        function selectAllRealms() {
            showWidgetLoader('Selecting all realms...');
            const toggles = document.querySelectorAll('#realms-permissions input[type="checkbox"]');
            let changedCount = 0;
            toggles.forEach(toggle => {
                if (!toggle.checked) {
                    toggle.click();
                    changedCount++;
                }
            });
            hideWidgetLoader();
            showWidgetNotice(`All realms selected (${changedCount} changed)`, 'success');
        }
        
        function deselectAllRealms() {
            showWidgetLoader('Deselecting all realms...');
            const toggles = document.querySelectorAll('#realms-permissions input[type="checkbox"]');
            let changedCount = 0;
            toggles.forEach(toggle => {
                if (toggle.checked) {
                    toggle.click();
                    changedCount++;
                }
            });
            hideWidgetLoader();
            showWidgetNotice(`All realms deselected (${changedCount} changed)`, 'info');
        }
        
        function selectAllMenus() {
            showWidgetLoader('Selecting all menus...');
            const toggles = document.querySelectorAll('#menus-permissions input[type="checkbox"]');
            let changedCount = 0;
            toggles.forEach(toggle => {
                if (!toggle.checked) {
                    toggle.click();
                    changedCount++;
                }
            });
            hideWidgetLoader();
            showWidgetNotice(`All menus selected (${changedCount} changed)`, 'success');
        }
        
        function deselectAllMenus() {
            showWidgetLoader('Deselecting all menus...');
            const toggles = document.querySelectorAll('#menus-permissions input[type="checkbox"]');
            let changedCount = 0;
            toggles.forEach(toggle => {
                if (toggle.checked) {
                    toggle.click();
                    changedCount++;
                }
            });
            hideWidgetLoader();
            showWidgetNotice(`All menus deselected (${changedCount} changed)`, 'info');
        }
        
        function selectAllSubmenus() {
            showWidgetLoader('Selecting all submenus...');
            const toggles = document.querySelectorAll('#submenus-permissions input[type="checkbox"]');
            let changedCount = 0;
            toggles.forEach(toggle => {
                if (!toggle.checked) {
                    toggle.click();
                    changedCount++;
                }
            });
            hideWidgetLoader();
            showWidgetNotice(`All submenus selected (${changedCount} changed)`, 'success');
        }
        
        function deselectAllSubmenus() {
            showWidgetLoader('Deselecting all submenus...');
            const toggles = document.querySelectorAll('#submenus-permissions input[type="checkbox"]');
            let changedCount = 0;
            toggles.forEach(toggle => {
                if (toggle.checked) {
                    toggle.click();
                    changedCount++;
                }
            });
            hideWidgetLoader();
            showWidgetNotice(`All submenus deselected (${changedCount} changed)`, 'info');
        }
        
        // Widget Integration Functions
        function showWidgetLoader(message = 'Loading...') {
            // Create or show loader widget
            let loader = document.getElementById('widget-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'widget-loader';
                loader.className = 'widget-loader';
                loader.innerHTML = `
                    <div style="background: rgba(0, 0, 0, 0.8); padding: 20px; border-radius: 10px; color: #00ffff; text-align: center;">
                        <div class="loading"></div>
                        <div style="margin-top: 10px;">${message}</div>
                    </div>
                `;
                document.body.appendChild(loader);
            }
            loader.style.display = 'block';
        }
        
        function hideWidgetLoader() {
            const loader = document.getElementById('widget-loader');
            if (loader) {
                loader.style.display = 'none';
            }
        }
        
        function showWidgetNotice(message, type = 'info') {
            const notice = document.createElement('div');
            notice.className = `widget-notice status-message status-${type}`;
            notice.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'info'}-circle"></i> ${message}`;
            notice.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                max-width: 300px;
                animation: slideInFromTop 0.5s ease-out;
            `;
            
            document.body.appendChild(notice);
            
            setTimeout(() => {
                notice.style.animation = 'slideOutToTop 0.5s ease-in';
                setTimeout(() => notice.remove(), 500);
            }, 3000);
        }
        
        // Legacy function cleanup - autosave moved to new location
        
        // Initialize page with aggressive cleanup
        
        // Show status messages
        function showStatus(message, type = 'info') {
            const container = document.getElementById('status-messages');
            const div = document.createElement('div');
            div.className = `status-message status-${type}`;
            div.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation' : 'info'}-circle"></i> ${message}`;
            container.appendChild(div);
            
            setTimeout(() => {
                div.remove();
            }, 5000);
        }
        
        // Generic fetch function
        function fetchJSON(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            
            Object.keys(data).forEach(key => {
                formData.append(key, data[key]);
            });
            
            return fetch('menu-permission-manager.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .catch(error => {
                console.error('Fetch error:', error);
                throw new Error('Network error');
            });
        }
        
        // Load users
        function loadUsers() {
            fetchJSON('load_users')
            .then(data => {
                const select = document.getElementById('user-select');
                if (data.success) {
                    select.innerHTML = '';
                    const opt0 = document.createElement('option');
                    opt0.value = '';
                    opt0.textContent = 'Select a user...';
                    select.appendChild(opt0);
                    data.users.forEach(user => {
                        const uid = (user.uid && String(user.uid).trim()) ? String(user.uid).trim() : '';
                        const username = (user.username && String(user.username).trim()) ? String(user.username).trim() : uid;
                        const realName = (user.name && String(user.name).trim()) ? String(user.name).trim() : (user.cn ? String(user.cn).trim() : '');
                        const label = realName ? `${realName} (${username || uid})` : `${username || uid}`;
                        const opt = document.createElement('option');
                        opt.value = uid || username;
                        opt.textContent = label;
                        opt.dataset.name = realName ? `${realName} (${username || uid})` : `${username || uid}`;
                        opt.dataset.username = username || '';
                        opt.dataset.roles = user.roles || '';
                        select.appendChild(opt);
                    });
                } else {
                    select.innerHTML = '<option value="">Error loading users</option>';
                    showStatus('Error loading users: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showStatus('Network error loading users', 'error');
            });
        }
        
        // Load realms
        function loadRealms() {
            return fetchJSON('load_realms')
            .then(data => {
                if (data.success) {
                    allRealms = data.realms;
                    return data.realms;
                } else {
                    showStatus('Error loading realms: ' + data.error, 'error');
                    return [];
                }
            })
            .catch(error => {
                showStatus('Network error loading realms', 'error');
                return [];
            });
        }
        
        // Load menus (optionally filtered by realm)
        function loadMenus(realmId = null) {
            const params = realmId ? { realm_id: realmId } : {};
            return fetchJSON('load_menus', params)
            .then(data => {
                if (data.success) {
                    if (realmId) {
                        allMenus = allMenus.filter(menu => menu.realm_id !== realmId).concat(data.menus);
                    } else {
                        allMenus = data.menus;
                    }
                    return allMenus;
                } else {
                    showStatus('Error loading menus: ' + data.error, 'error');
                    return [];
                }
            })
            .catch(() => []);
        }
        
        // Load submenus
        function loadSubmenus(menuId = null) {
            const params = menuId ? { menu_id: menuId } : {};
            return fetchJSON('load_submenus', params)
            .then(data => {
                if (data.success) {
                    if (menuId) {
                        // Store filtered submenus for the specific menu
                        allSubmenus = allSubmenus.filter(submenu => submenu.parent_id !== menuId).concat(data.submenus);
                    } else {
                        allSubmenus = data.submenus;
                    }
                    console.log('Loaded submenus:', data.submenus.length);
                    return data.submenus;
                } else {
                    showStatus('Error loading submenus: ' + data.error, 'error');
                    return [];
                }
            })
            .catch(error => {
                console.error('Error loading submenus:', error);
                showStatus('Network error loading submenus', 'error');
                return [];
            });
        }
        
        // Select user with enhanced display
        function selectUser() {
            const select = document.getElementById('user-select');
            const selectedOption = select.selectedOptions[0];
            
            if (!selectedOption || !selectedOption.value) {
                document.getElementById('selected-user-info').style.display = 'none';
                document.getElementById('permissions-content').innerHTML = '<div class="status-message status-info"><i class="fas fa-info-circle"></i> Please select a user to manage their permissions.</div>';
                showNotificationWidget('Please select a user first', 'info');
                return;
            }
            
            currentUser = {
                uid: selectedOption.value,
                name: selectedOption.dataset.name,
                realm: selectedOption.dataset.realm,
                roles: selectedOption.dataset.roles || ''
            };
            
            // Display user details with enhanced cyan styling
            displayUserInfo();
            
            // Setup role assignment with fresh approach
            initializeRoleAssignment();
            
            // Show permissions panel
            displayPermissionsPanel();
            
            // Reset permissions state
            userPermissions = {
                realms: [],
                menus: [],
                submenus: []
            };
            
            // Show success notification
            showNotificationWidget(`User ${currentUser.name} selected`, 'success');
            setTimeout(() => {
                loadUserPermissions(true);
            }, 50);
        }
        
        // Display user information with proper cyan theming
        function displayUserInfo() {
            const userDetailsContainer = document.getElementById('user-details-display');
            if (!userDetailsContainer) return;
            
            userDetailsContainer.innerHTML = `
                <div style="text-align: center;">
                    <h4 style="color: #00ffff; margin: 0 0 10px 0; font-size: 18px; text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);">
                        <i class="fas fa-user-circle"></i> ${currentUser.name}
                    </h4>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; text-align: left;">
                        <div style="color: #00ffff; font-size: 13px;"><strong>ID:</strong> ${currentUser.uid}</div>
                        <div style="color: #00ffff; font-size: 13px;"><strong>Role:</strong> ${currentUser.roles ? availableRoles[currentUser.roles]?.name || currentUser.roles : 'None'}</div>
                    </div>
                </div>
            `;
            
            document.getElementById('selected-user-info').style.display = 'block';
        }
        
        // Initialize role assignment with fresh approach
        function initializeRoleAssignment() {
            const roleSelect = document.getElementById('role-selector-v2');
            const statusDiv = document.getElementById('role-assignment-status');
            
            if (!roleSelect) return;
            
            // Clear and rebuild role options
            roleSelect.innerHTML = '<option value="" style="background: #1a1a2e; color: #00ffff;">Choose a role...</option>';
            
            Object.keys(availableRoles).forEach(roleKey => {
                const role = availableRoles[roleKey];
                const isSelected = currentUser.roles === roleKey;
                const option = document.createElement('option');
                option.value = roleKey;
                option.textContent = role.name;
                option.title = role.description;
                option.style.background = '#1a1a2e';
                option.style.color = '#00ffff';
                if (isSelected) {
                    option.selected = true;
                }
                roleSelect.appendChild(option);
            });
            
            // Update status
            if (statusDiv) {
                if (currentUser.roles) {
                    statusDiv.innerHTML = `<i class="fas fa-check-circle" style="color: #2ecc71;"></i> Current: ${availableRoles[currentUser.roles]?.name || currentUser.roles}`;
                } else {
                    statusDiv.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #f39c12;"></i> No role assigned';
                }
            }
        }
        
        // Assign user role (new function name to avoid conflicts)
        function assignUserRole() {
            if (!currentUser) {
                showNotificationWidget('No user selected', 'error');
                return;
            }
            
            const roleSelect = document.getElementById('role-selector-v2');
            const statusDiv = document.getElementById('role-assignment-status');
            const selectedRole = roleSelect.value;
            
            if (!selectedRole) {
                if (statusDiv) statusDiv.innerHTML = '<i class="fas fa-info-circle" style="color: #3498db;"></i> Please select a role';
                return;
            }
            
            // Show loading state
            if (statusDiv) statusDiv.innerHTML = '<i class="fas fa-spinner fa-spin" style="color: #00ffff;"></i> Saving role assignment...';
            showLoadingWidget('Assigning role...');
            
            // Save role
            fetchJSON('save_user_role', {
                user_id: currentUser.uid,
                role: selectedRole
            })
            .then(data => {
                hideLoadingWidget();
                if (data.success) {
                    currentUser.roles = selectedRole;
                    const roleName = availableRoles[selectedRole]?.name || selectedRole;
                    
                    if (statusDiv) {
                        statusDiv.innerHTML = `<i class="fas fa-check-circle" style="color: #2ecc71;"></i> Successfully assigned: ${roleName}`;
                    }
                    
                    // Update user display
                    displayUserInfo();
                    // Auto-select and save full permissions for KripzMasters
                    if (selectedRole === 'kripzmasters') {
                        Promise.all([loadRealms(), loadMenus()])
                        .then(() => {
                            userPermissions.realms = (allRealms || []).map(r => String(r.id));
                            userPermissions.menus = (allMenus || []).map(m => String(m.id));
                            return fetchJSON('load_submenus', { menu_ids: JSON.stringify(userPermissions.menus) });
                        })
                        .then(data => {
                            if (data && data.success) {
                                allSubmenus = data.submenus || [];
                                userPermissions.submenus = (allSubmenus || []).map(s => String(s.id));
                            }
                            populateRealmsPermissions();
                            toggleMenusSection();
                            populateMenusPermissions();
                            populateSubmenusPermissions();
                            savePermissions(true);
                            showNotificationWidget('KripzMasters: full permissions applied and saved', 'success');
                        })
                        .catch(() => {
                            showNotificationWidget('KripzMasters auto-permission setup encountered an error', 'error');
                        });
                    } else if (selectedRole === 'users') {
                        Promise.all([loadRealms(), loadMenus()])
                        .then(() => {
                            userPermissions.realms = ['hub'];
                            const hubMenus = (allMenus || []).filter(m => String(m.realm_id) === 'hub').map(m => String(m.id));
                            userPermissions.menus = hubMenus;
                            if (hubMenus.length === 0) {
                                allSubmenus = [];
                                userPermissions.submenus = [];
                                return { success: true, submenus: [] };
                            }
                            return fetchJSON('load_submenus', { menu_ids: JSON.stringify(hubMenus) });
                        })
                        .then(data => {
                            if (data && data.success) {
                                allSubmenus = data.submenus || [];
                                userPermissions.submenus = (allSubmenus || []).map(s => String(s.id));
                            } else {
                                userPermissions.submenus = [];
                            }
                            populateRealmsPermissions();
                            toggleMenusSection();
                            populateMenusPermissions();
                            populateSubmenusPermissions();
                            savePermissions(true);
                            showNotificationWidget('Users: /hub default permissions applied and saved', 'success');
                        })
                        .catch(() => {
                            showNotificationWidget('Users default permission setup encountered an error', 'error');
                        });
                    }
                    showNotificationWidget(`Role updated to ${roleName}`, 'success');
                } else {
                    if (statusDiv) statusDiv.innerHTML = `<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Error: ${data.error}`;
                    showNotificationWidget('Failed to update role', 'error');
                }
            })
            .catch(error => {
                hideLoadingWidget();
                if (statusDiv) statusDiv.innerHTML = '<i class="fas fa-times-circle" style="color: #e74c3c;"></i> Network error';
                showNotificationWidget('Network error occurred', 'error');
            });
        }
        
        // Toggle menus section visibility based on realm selection
        function toggleMenusSection() {
            const menusSection = document.getElementById('menus-section');
            const submenusSection = document.getElementById('submenus-section');
            const hasSelectedRealms = userPermissions.realms && userPermissions.realms.length > 0;
            
            if (hasSelectedRealms) {
                menusSection.style.display = 'block';
                submenusSection.style.display = 'block';
            } else {
                menusSection.style.display = 'none';
                submenusSection.style.display = 'none';
                // Clear menu and submenu permissions when no realms selected
                userPermissions.menus = [];
                userPermissions.submenus = [];
            }
        }

        // Display permissions panel
        function displayPermissionsPanel() {
            const content = document.getElementById('permissions-content');
            
            let html = `
                <div class="permission-section">
                    <div class="select-all-controls" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 15px 20px; background: rgba(0, 255, 255, 0.1); border-radius: 8px; border: 2px solid rgba(0, 255, 255, 0.3); backdrop-filter: blur(10px);">
                        <h3 style="color: #00ffff; text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);"><i class="fas fa-globe"></i> Realms Access</h3>
                        <div>
                            <button type="button" onclick="bulkSelectRealms(true)" style="background: linear-gradient(45deg, #00ffff, #0099ff); color: #1a1a2e; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; margin: 0 8px; box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0, 255, 255, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 255, 255, 0.3)';"><i class="fas fa-check-circle"></i> Select All</button>
                            <button type="button" onclick="bulkSelectRealms(false)" style="background: linear-gradient(45deg, #ff4757, #ff3742); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; margin: 0 8px; box-shadow: 0 4px 15px rgba(255, 71, 87, 0.3); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(255, 71, 87, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(255, 71, 87, 0.3)';"><i class="fas fa-times-circle"></i> Deselect All</button>
                        </div>
                    </div>
                    <div id="realms-permissions">
                        <div class="loading" style="color: #00ffff; text-align: center; padding: 20px;">Loading realms...</div>
                    </div>
                </div>
                
                <div class="permission-section" id="menus-section" style="display: none;">
                    <div class="select-all-controls" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 15px 20px; background: rgba(0, 255, 255, 0.1); border-radius: 8px; border: 2px solid rgba(0, 255, 255, 0.3); backdrop-filter: blur(10px);">
                        <h3 style="color: #00ffff; text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);"><i class="fas fa-list"></i> Menus Access</h3>
                        <div>
                            <button type="button" onclick="bulkSelectMenus(true)" style="background: linear-gradient(45deg, #00ffff, #0099ff); color: #1a1a2e; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; margin: 0 8px; box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0, 255, 255, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 255, 255, 0.3)';"><i class="fas fa-check-circle"></i> Select All</button>
                            <button type="button" onclick="bulkSelectMenus(false)" style="background: linear-gradient(45deg, #ff4757, #ff3742); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; margin: 0 8px; box-shadow: 0 4px 15px rgba(255, 71, 87, 0.3); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(255, 71, 87, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(255, 71, 87, 0.3)';"><i class="fas fa-times-circle"></i> Deselect All</button>
                        </div>
                    </div>
                    <div id="menus-permissions">
                        <div class="loading" style="color: #00ffff; text-align: center; padding: 20px;">Loading menus...</div>
                    </div>
                </div>
                
                <div class="permission-section" id="submenus-section" style="display: none;">
                    <div class="select-all-controls" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding: 15px 20px; background: rgba(0, 255, 255, 0.1); border-radius: 8px; border: 2px solid rgba(0, 255, 255, 0.3); backdrop-filter: blur(10px);">
                        <h3 style="color: #00ffff; text-shadow: 0 0 10px rgba(0, 255, 255, 0.5);"><i class="fas fa-sitemap"></i> Submenus Access</h3>
                        <div>
                            <button type="button" onclick="bulkSelectSubmenus(true)" style="background: linear-gradient(45deg, #00ffff, #0099ff); color: #1a1a2e; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; margin: 0 8px; box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0, 255, 255, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0, 255, 255, 0.3)';"><i class="fas fa-check-circle"></i> Select All</button>
                            <button type="button" onclick="bulkSelectSubmenus(false)" style="background: linear-gradient(45deg, #ff4757, #ff3742); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 13px; margin: 0 8px; box-shadow: 0 4px 15px rgba(255, 71, 87, 0.3); transition: all 0.3s ease;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(255, 71, 87, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(255, 71, 87, 0.3)';"><i class="fas fa-times-circle"></i> Deselect All</button>
                        </div>
                    </div>
                    <div id="submenus-permissions">
                        <div class="loading" style="color: #00ffff; text-align: center; padding: 20px;">Loading submenus...</div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
            
            // Populate permissions
            populateRealmsPermissions();
            toggleMenusSection(); // Check initial state
            populateMenusPermissions();
            populateSubmenusPermissions();
        }
        
        // Helper to generate icon HTML
        function getIconHtml(iconVal) {
            if (!iconVal) return '';
            let rcls = iconVal;
            if (iconVal.startsWith('fa-')) { rcls = 'fa ' + iconVal; }
            else if (!iconVal.includes('fa ') && !iconVal.includes('fas ') && !iconVal.includes('far ') && !iconVal.includes('fab ') && !iconVal.includes('ph ') && !iconVal.includes('iconoir')) { rcls = 'fa fa-' + iconVal; }
            return `<i class="${rcls}" style="margin-right: 8px; width: 20px; text-align: center; color: #00ffff;"></i>`;
        }

        // Populate realms permissions
        function populateRealmsPermissions() {
            const container = document.getElementById('realms-permissions');
            
            if (allRealms.length === 0) {
                container.innerHTML = '<p>No realms available.</p>';
                return;
            }
            
            let html = '';
            allRealms.forEach(realm => {
                const isChecked = userPermissions.realms.includes(String(realm.id)) ? 'checked' : '';
                const iconHtml = getIconHtml(realm.icon);
                html += `
                    <div class="toggle-item">
                        <div class="toggle-info">
                            <div class="toggle-name">${iconHtml}${realm.name}</div>
                            <div class="toggle-description">${realm.description || 'No description available'}</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" ${isChecked} onchange="togglePermission('realm', '${realm.id}')">
                            <span class="slider"></span>
                        </label>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Populate menus permissions
        function populateMenusPermissions() {
            const container = document.getElementById('menus-permissions');
            
            // Filter menus by selected realms - only show if realms are selected
            const selectedRealms = userPermissions.realms || [];
            
            // If no realms selected, show message and return
            if (selectedRealms.length === 0) {
                container.innerHTML = `<p style="color: #aaa; text-align: center; padding: 20px;">Please select a realm first to see available menus.</p>`;
                return;
            }
            
            // Filter menus to only those in selected realms
            const filteredMenus = allMenus.filter(menu => selectedRealms.includes(String(menu.realm_id)));
            // Ensure any already-selected menu IDs are shown even if not present in allMenus
            const allMenuIdSet = new Set(allMenus.map(m => String(m.id)));
            const extraMenus = (userPermissions.menus || [])
                .filter(id => !allMenuIdSet.has(String(id)))
                .map(id => ({ id: String(id), title: String(id), name: String(id), url: '', realm_id: null, icon: '' }));
            const menusToShow = filteredMenus.concat(extraMenus);
            
            if (menusToShow.length === 0) {
                container.innerHTML = `<p style="color: #aaa; text-align: center; padding: 20px;">No menus available for the selected realm(s).</p>`;
                return;
            }
            
            console.log('Selected realms:', selectedRealms);
            console.log('All menus:', allMenus);
            console.log('Menus to show:', menusToShow);
            
            let html = '';
            menusToShow.forEach(menu => {
                const isChecked = userPermissions.menus.includes(String(menu.id)) ? 'checked' : '';
                const iconHtml = getIconHtml(menu.icon);
                html += `
                    <div class="toggle-item">
                        <div class="toggle-info">
                            <div class="toggle-name">${iconHtml}${menu.title || menu.name}</div>
                            <div class="toggle-description">ID: ${menu.id} | ${menu.url || 'No URL specified'}</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" ${isChecked} onchange="togglePermission('menu', '${menu.id}')">
                            <span class="slider"></span>
                        </label>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Populate submenus permissions
        function populateSubmenusPermissions() {
            const container = document.getElementById('submenus-permissions');
            
            // Get selected menu IDs
            const selectedMenus = userPermissions.menus || [];
            
            if (selectedMenus.length === 0) {
                container.innerHTML = '<p style="color: #aaa; text-align: center; padding: 20px;">Select menus to manage submenu permissions.</p>';
                return;
            }
            
            container.innerHTML = '<div class="loading">Loading submenus...</div>';
            
            fetchJSON('load_submenus', { menu_ids: JSON.stringify(selectedMenus) })
            .then(data => {
                if (data.success) {
                    if (data.submenus.length === 0) {
                        container.innerHTML = '<p style="color: #aaa; text-align: center; padding: 20px;">No additional menus available in the selected realm(s).</p>';
                        return;
                    }
                    
                    let html = '';
                    data.submenus.forEach(submenu => {
                        const isChecked = userPermissions.submenus.includes(String(submenu.id)) ? 'checked' : '';
                        const iconHtml = getIconHtml(submenu.icon);
                        html += `
                            <div class="toggle-item">
                                <div class="toggle-info">
                                    <div class="toggle-name">${iconHtml}${submenu.title || submenu.name}</div>
                                    <div class="toggle-description">${submenu.url || 'No URL specified'}</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" ${isChecked} onchange="togglePermission('submenu', '${submenu.id}')">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        `;
                    });
                    
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p style="color: #e74c3c;">Error loading submenus: ' + data.error + '</p>';
                }
            })
            .catch(error => {
                container.innerHTML = '<p style="color: #e74c3c;">Network error loading submenus</p>';
            });
        }
        
        // Toggle permission
        function togglePermission(type, id) {
            if (type === 'realm') {
                const index = userPermissions.realms.indexOf(id);
                if (index > -1) {
                    userPermissions.realms.splice(index, 1);
                } else {
                    userPermissions.realms.push(id);
                }
                // Show/hide menus section and reload when realm selection changes
                setTimeout(() => {
                    toggleMenusSection();
                    populateMenusPermissions();
                    populateSubmenusPermissions();
                }, 100);
            } else if (type === 'menu') {
                const index = userPermissions.menus.indexOf(id);
                if (index > -1) {
                    userPermissions.menus.splice(index, 1);
                    // Clear submenus when menu is deselected
                    userPermissions.submenus = [];
                } else {
                    userPermissions.menus.push(id);
                }
                // Refresh submenus when menu selection changes
                setTimeout(() => populateSubmenusPermissions(), 100);
            } else if (type === 'submenu') {
                const index = userPermissions.submenus.indexOf(id);
                if (index > -1) {
                    userPermissions.submenus.splice(index, 1);
                } else {
                    userPermissions.submenus.push(id);
                }
            }
        }
        
        // Load user permissions
        function loadUserPermissions(silent = false) {
            if (!currentUser) {
                if (!silent) {
                    showStatus('Please select a user first', 'error');
                }
                return;
            }
            
            const loadBtn = document.getElementById('load-permissions-btn');
            if (loadBtn) {
                loadBtn.disabled = true;
                loadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            }
            fetchJSON('load_user_permissions', { user_id: currentUser.uid })
            .then(data => {
                if (data.success) {
                    const perms = data.permissions || { realms: [], menus: [], submenus: [] };
                    userPermissions = {
                        realms: (perms.realms || []).map(id => String(id)),
                        menus: (perms.menus || []).map(id => String(id)),
                        submenus: (perms.submenus || []).map(id => String(id))
                    };
                    
                    // Ensure realms/menus are loaded before rendering checks
                    Promise.all([loadRealms(), loadMenus()])
                    .then(() => {
                        populateRealmsPermissions();
                        toggleMenusSection();
                        populateMenusPermissions();
                        populateSubmenusPermissions();
                        if (!silent) {
                            showStatus('User permissions loaded successfully', 'success');
                        }
                    });
                } else {
                    if (!silent) {
                        showStatus('Error loading user permissions: ' + data.error, 'error');
                    }
                }
            })
            .catch(error => {
                if (!silent) {
                    showStatus('Network error loading permissions', 'error');
                }
            })
            .finally(() => {
                if (loadBtn) {
                    loadBtn.disabled = false;
                    loadBtn.innerHTML = '<i class="fas fa-cog"></i> Load Permissions';
                }
            });
        }
        
        // Save permissions
        function savePermissions(silent = false) {
            if (!currentUser) {
                showStatus('Please select a user first', 'error');
                return;
            }
            
            const data = {
                user_id: currentUser.uid,
                realms: JSON.stringify(userPermissions.realms),
                menus: JSON.stringify(userPermissions.menus),
                submenus: JSON.stringify(userPermissions.submenus)
            };
            
            fetchJSON('save_user_permissions', data)
            .then(data => {
                if (data.success) {
                    showStatus('Permissions saved successfully', 'success');
                } else {
                    showStatus('Error saving permissions: ' + data.error, 'error');
                }
            })
            .catch(error => {
                showStatus('Network error saving permissions', 'error');
            });
        }
        
        // Enhanced Bulk Selection Functions
        function bulkSelectRealms(selectAll = true) {
            const action = selectAll ? 'Selecting' : 'Deselecting';
            showLoadingWidget(`${action} all realms...`);
            
            const toggles = document.querySelectorAll('#realms-permissions input[type="checkbox"]');
            let changed = 0;
            
            toggles.forEach(toggle => {
                if (selectAll && !toggle.checked) {
                    toggle.click();
                    changed++;
                } else if (!selectAll && toggle.checked) {
                    toggle.click();
                    changed++;
                }
            });
            
            setTimeout(() => {
                hideLoadingWidget();
                const message = selectAll ? 
                    `All realms selected (${changed} changed)` : 
                    `All realms deselected (${changed} changed)`;
                showNotificationWidget(message, selectAll ? 'success' : 'info');
            }, 300);
        }
        
        function bulkSelectMenus(selectAll = true) {
            const action = selectAll ? 'Selecting' : 'Deselecting';
            showLoadingWidget(`${action} all menus...`);
            
            const toggles = document.querySelectorAll('#menus-permissions input[type="checkbox"]');
            let changed = 0;
            
            toggles.forEach(toggle => {
                if (selectAll && !toggle.checked) {
                    toggle.click();
                    changed++;
                } else if (!selectAll && toggle.checked) {
                    toggle.click();
                    changed++;
                }
            });
            
            setTimeout(() => {
                hideLoadingWidget();
                const message = selectAll ? 
                    `All menus selected (${changed} changed)` : 
                    `All menus deselected (${changed} changed)`;
                showNotificationWidget(message, selectAll ? 'success' : 'info');
            }, 300);
        }
        
        function bulkSelectSubmenus(selectAll = true) {
            const action = selectAll ? 'Selecting' : 'Deselecting';
            showLoadingWidget(`${action} all submenus...`);
            
            const toggles = document.querySelectorAll('#submenus-permissions input[type="checkbox"]');
            let changed = 0;
            
            toggles.forEach(toggle => {
                if (selectAll && !toggle.checked) {
                    toggle.click();
                    changed++;
                } else if (!selectAll && toggle.checked) {
                    toggle.click();
                    changed++;
                }
            });
            
            setTimeout(() => {
                hideLoadingWidget();
                const message = selectAll ? 
                    `All submenus selected (${changed} changed)` : 
                    `All submenus deselected (${changed} changed)`;
                showNotificationWidget(message, selectAll ? 'success' : 'info');
            }, 300);
        }
        
        // Enhanced Widget Integration Functions
        function showLoadingWidget(message = 'Loading...') {
            let loader = document.getElementById('loading-widget-v2');
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'loading-widget-v2';
                loader.style.cssText = `
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    z-index: 99999;
                    display: block;
                `;
                document.body.appendChild(loader);
            }
            
            loader.innerHTML = `
                <div style="background: rgba(0, 0, 0, 0.9); padding: 30px 40px; border-radius: 15px; color: #00ffff; text-align: center; backdrop-filter: blur(15px); border: 2px solid rgba(0, 255, 255, 0.5); box-shadow: 0 8px 32px rgba(0, 255, 255, 0.3);">
                    <div style="display: flex; align-items: center; justify-content: center; gap: 15px;">
                        <div class="loading-spinner" style="width: 25px; height: 25px; border: 3px solid rgba(0, 255, 255, 0.3); border-top: 3px solid #00ffff; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                        <div style="font-size: 16px; font-weight: bold;">${message}</div>
                    </div>
                </div>
            `;
            loader.style.display = 'block';
        }
        
        function encryptAllPins() {
            showLoadingWidget('Encrypting all PINs...');
            fetchJSON('encrypt_all_pins')
            .then(data => {
                hideLoadingWidget();
                if (data.success) {
                    const s = data.stats || {};
                    const status = document.getElementById('security-utilities-status');
                    if (status) {
                        status.textContent = `Encrypted: ${s.updated || 0}, Skipped: ${s.skipped || 0}, Errors: ${s.errors || 0}, Scanned: ${s.scanned || 0}`;
                    }
                    showStatus('PIN encryption completed', 'success');
                } else {
                    showStatus('Error encrypting pins: ' + data.error, 'error');
                }
            })
            .catch(() => {
                hideLoadingWidget();
                showStatus('Network error encrypting pins', 'error');
            });
        }
        
        function copyUidToUsername() {
            if (!currentUser || !currentUser.uid) {
                showStatus('Please select a user first', 'error');
                return;
            }
            const uid = currentUser.uid;
            showLoadingWidget('Copying UID to Username...');
            fetchJSON('save_user_username', { user_id: uid, username: uid })
            .then(data => {
                hideLoadingWidget();
                if (data.success) {
                    const status = document.getElementById('security-utilities-status');
                    if (status) {
                        status.textContent = `Username updated for ${uid}`;
                    }
                    showStatus('Username updated successfully', 'success');
                    loadUsers();
                    setTimeout(() => {
                        const select = document.getElementById('user-select');
                        if (select) {
                            select.value = uid;
                            selectUser();
                        }
                    }, 300);
                } else {
                    showStatus('Error updating username: ' + data.error, 'error');
                }
            })
            .catch(() => {
                hideLoadingWidget();
                showStatus('Network error updating username', 'error');
            });
        }
        
        function hideLoadingWidget() {
            const loader = document.getElementById('loading-widget-v2');
            if (loader) {
                loader.style.display = 'none';
            }
        }
        
        function showNotificationWidget(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification-widget-v2 status-${type}`;
            
            const colors = {
                success: { bg: 'rgba(46, 204, 113, 0.15)', border: '#2ecc71', text: '#2ecc71', icon: 'check-circle' },
                error: { bg: 'rgba(231, 76, 60, 0.15)', border: '#e74c3c', text: '#e74c3c', icon: 'exclamation-circle' },
                info: { bg: 'rgba(52, 152, 219, 0.15)', border: '#3498db', text: '#3498db', icon: 'info-circle' },
                warning: { bg: 'rgba(243, 156, 18, 0.15)', border: '#f39c12', text: '#f39c12', icon: 'exclamation-triangle' }
            };
            
            const color = colors[type] || colors.info;
            
            notification.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px;">
                    <i class="fas fa-${color.icon}" style="color: ${color.text}; font-size: 18px;"></i>
                    <span style="color: ${color.text}; font-weight: bold;">${message}</span>
                </div>
            `;
            
            notification.style.cssText = `
                position: fixed;
                top: 30px;
                right: 30px;
                z-index: 100000;
                min-width: 300px;
                max-width: 400px;
                padding: 18px 25px;
                border-radius: 12px;
                backdrop-filter: blur(10px);
                background: ${color.bg};
                border: 2px solid ${color.border};
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
                animation: slideInFromRight 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                cursor: pointer;
            `;
            
            // Auto-remove notification
            notification.onclick = () => removeNotification(notification);
            document.body.appendChild(notification);
            
            setTimeout(() => removeNotification(notification), 4000);
        }
        
        function removeNotification(notification) {
            if (notification && notification.parentNode) {
                notification.style.animation = 'slideOutToRight 0.4s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 400);
            }
        }

        function enableAutosave() {
            // Create autosave indicator
            if (!document.getElementById('autosave-indicator')) {
                const indicator = document.createElement('div');
                indicator.id = 'autosave-indicator';
                indicator.style.cssText = `
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    z-index: 1000;
                    background: rgba(0, 255, 255, 0.1);
                    padding: 10px;
                    border-radius: 8px;
                    border: 1px solid rgba(0, 255, 255, 0.3);
                    color: #00ffff;
                    font-size: 12px;
                `;
                indicator.innerHTML = `<i class="fas fa-save"></i> Autosave: ON`;
                document.body.appendChild(indicator);
            }
        }
        
        // Legacy DOM ready event - removed duplicate
        
        // Aggressive cleanup function
        function forceCleanupRealmSelectors() {
            // Remove any realm selectors from role assignment panel
            const rolePanel = document.getElementById('role-selection-panel');
            if (rolePanel) {
                const realmElements = rolePanel.querySelectorAll('*');
                realmElements.forEach(el => {
                    if (el.textContent && el.textContent.toLowerCase().includes('realm') && el.id !== 'user-role-select') {
                        if (el.tagName === 'SELECT' || el.tagName === 'LABEL' || el.tagName === 'DIV') {
                            el.remove();
                        }
                    }
                });
            }
        }
        
        // Run cleanup every 2 seconds for the first minute to catch any dynamic additions
        let cleanupInterval = setInterval(forceCleanupRealmSelectors, 2000);
        setTimeout(() => clearInterval(cleanupInterval), 60000);
        
        // Enhanced CSS animations and theming
        const enhancedStyles = document.createElement('style');
        enhancedStyles.textContent = [
            '@keyframes slideInFromRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }',
            '@keyframes slideOutToRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }',
            '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }',
            '@keyframes pulse { 0% { box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); } 50% { box-shadow: 0 6px 25px rgba(0, 255, 255, 0.6); } 100% { box-shadow: 0 4px 15px rgba(0, 255, 255, 0.3); } }',
            '#user-details-display, #user-details-display * { color: #00ffff !important; }',
            '.select-all-controls button:hover { animation: pulse 1.5s infinite; }',
            '.loading { position: relative; }',
            '.loading::after { content: ""; display: inline-block; width: 20px; height: 20px; border: 2px solid rgba(0, 255, 255, 0.3); border-radius: 50%; border-top-color: #00ffff; animation: spin 1s linear infinite; margin-left: 10px; vertical-align: middle; }',
            '#role-selector-v2:focus { outline: none; box-shadow: 0 0 0 3px rgba(0, 255, 255, 0.3); }',
            '@keyframes slideInFromRightEnhanced { from { transform: translateX(100%); opacity: 0; scale: 0.8; } to { transform: translateX(0); opacity: 1; scale: 1; } }'

        ].join('\n');
        document.head.appendChild(enhancedStyles);
        
        // Initialize page functionality
        document.addEventListener('DOMContentLoaded', function() {
            loadUsers();
            loadRealms();
            loadMenus();
            loadSubmenus();
            enableAutosave();
        });
    </script>

    </div>
    </main>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
