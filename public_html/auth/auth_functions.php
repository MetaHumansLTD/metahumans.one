<?php
/**
 * Shared Authentication Functions
 */

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/tenant_provisioning.php';
require_once __DIR__ . '/persona_registry.php';

if (!function_exists('mh_username_is_reserved_prefix')) {
    function mh_username_is_reserved_prefix(string $username): bool {
        $username = trim($username);
        if ($username === '') return false;
        return (bool)preg_match('/^(metahuman|anon|device)/i', $username);
    }
}

if (!function_exists('mh_load_biometrics_user')) {
    function mh_load_biometrics_user(mixed $username, mixed $groups = null, mixed $unused = null): bool {
        try {
            // Ensure database module is loaded
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }

            $requestPath = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
            $emailHint = is_string($unused) && filter_var(trim($unused), FILTER_VALIDATE_EMAIL)
                ? trim($unused)
                : '';
            if (function_exists('database_isTenantScopedRequest') && database_isTenantScopedRequest($requestPath, $scriptName)) {
                $username = is_string($username) ? trim((string)$username) : '';
                if ($username === '') {
                    return false;
                }
                if (session_status() === PHP_SESSION_ACTIVE) {
                    if (!isset($_SESSION['mh_auth_user']) || (string)$_SESSION['mh_auth_user'] === '') {
                        $_SESSION['mh_auth_user'] = $username;
                    }
                    if ($emailHint !== '') {
                        $_SESSION['mh_auth_email'] = $emailHint;
                    }
                    $tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id'])
                        ? trim((string)$_SESSION['mh_tenant_id'])
                        : '';
                    $hasDbPreference = (
                        (isset($_SESSION['mh_db_preference']) && is_string($_SESSION['mh_db_preference']) && trim((string)$_SESSION['mh_db_preference']) !== '')
                        || (isset($_SESSION['current_database_config_id']) && is_string($_SESSION['current_database_config_id']) && trim((string)$_SESSION['current_database_config_id']) !== '')
                    );

                    if ($tenantId === '' || !$hasDbPreference) {
                        try {
                            if (function_exists('mh_auth_user_store_pdo')) {
                                $pdoBio = mh_auth_user_store_pdo();
                                $stmt = $pdoBio->prepare("SELECT id, name, persona_name, tenant_id, role, permissions, `groups`, email, device_id, genesis_status, tokens, token_usage FROM users WHERE username = ? LIMIT 1");
                                $stmt->execute([$username]);
                                $tenantUser = $stmt->fetch(PDO::FETCH_ASSOC);

                                if (is_array($tenantUser)) {
                                    $tenantId = isset($tenantUser['tenant_id']) ? trim((string)$tenantUser['tenant_id']) : $tenantId;
                                    if ($tenantId === '') {
                                        $tenantId = 'user:' . $username;
                                    }

                                    $_SESSION['mh_user_internal_id'] = $tenantUser['id'] ?? ($_SESSION['mh_user_internal_id'] ?? null);
                                    $_SESSION['mh_auth_persona'] = $tenantUser['persona_name'] ?? ($_SESSION['mh_auth_persona'] ?? null);
                                    $_SESSION['mh_auth_display'] = (isset($tenantUser['persona_name']) && trim((string)$tenantUser['persona_name']) !== '')
                                        ? (string)$tenantUser['persona_name']
                                        : ((isset($tenantUser['name']) && trim((string)$tenantUser['name']) !== '') ? (string)$tenantUser['name'] : ($_SESSION['mh_auth_display'] ?? $username));
                                    $_SESSION['mh_auth_permissions'] = $tenantUser['permissions'] ?? ($_SESSION['mh_auth_permissions'] ?? null);
                                    $_SESSION['mh_auth_groups'] = $tenantUser['groups'] ?? ($_SESSION['mh_auth_groups'] ?? null);
                                    $_SESSION['mh_auth_email'] = (isset($tenantUser['email']) && trim((string)$tenantUser['email']) !== '')
                                        ? (string)$tenantUser['email']
                                        : ($_SESSION['mh_auth_email'] ?? null);
                                    $_SESSION['mh_device_id'] = $tenantUser['device_id'] ?? ($_SESSION['mh_device_id'] ?? null);
                                    $_SESSION['mh_genesis_status'] = $tenantUser['genesis_status'] ?? ($_SESSION['mh_genesis_status'] ?? 0);
                                    $_SESSION['tokens'] = $tenantUser['tokens'] ?? ($_SESSION['tokens'] ?? 0);
                                    $_SESSION['token_usage'] = $tenantUser['token_usage'] ?? ($_SESSION['token_usage'] ?? 0);

                                    $rawRole = (string)($tenantUser['role'] ?? '');
                                    if ($rawRole !== '') {
                                        $_SESSION['mh_auth_role'] = (stripos($rawRole, 'kripzmaster') !== false) ? 'KripzMasters' : 'Users';
                                    }
                                }
                            }
                        } catch (Throwable) {
                        }
                    }

                    if ($tenantId === '') {
                        $tenantId = 'user:' . $username;
                    }

                    $_SESSION['mh_tenant_id'] = $tenantId;
                    if (!isset($_SESSION['mh_persona_tenant_id']) && isset($_SESSION['mh_auth_persona']) && is_string($_SESSION['mh_auth_persona']) && trim((string)$_SESSION['mh_auth_persona']) !== '') {
                        $_SESSION['mh_persona_tenant_id'] = 'persona:' . trim((string)$_SESSION['mh_auth_persona']);
                    }

                    try {
                        if (function_exists('mh_provision_tenant_storage')) {
                            mh_provision_tenant_storage($tenantId);
                        }
                        if (function_exists('mh_apply_tenant_context')) {
                            mh_apply_tenant_context($tenantId);
                        }
                    } catch (Throwable $e) {
                        error_log('Tenant context apply skipped: ' . $e->getMessage());
                    }

                    if (function_exists('mh_get_token_balance')) {
                        $bal = mh_get_token_balance($username);
                        if (is_int($bal)) {
                            $_SESSION['tokens'] = $bal;
                        }
                    }
                }
                return true;
            }

            // 1. Determine Role based on groups (SSO) if provided
            $ssoRole = null;
            $groupsStr = is_array($groups) ? implode(';', $groups) : $groups;
            
            if ($groupsStr) {
                if (stripos($groupsStr, 'KripzMasters') !== false) {
                    $ssoRole = 'KripzMasters';
                } else {
                    $ssoRole = 'Users';
                }
            }

            try {
                if (!function_exists('database_getConnectionById')) {
                    throw new RuntimeException('database_getConnectionById unavailable');
                }
                $pdoBio = call_user_func('database_getConnectionById', 'biometrics');
            } catch (Throwable $e) {
                error_log("Biometrics Connection Failed: " . $e->getMessage());
                return false;
            }

            try {
                $pdoBio->query("SELECT groups FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN groups TEXT DEFAULT NULL AFTER role"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT permissions FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN permissions JSON DEFAULT NULL AFTER role"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT genesis_status FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN genesis_status TINYINT DEFAULT 0 AFTER groups"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT tokens FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN tokens INT DEFAULT 0 AFTER permissions"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT token_usage FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN token_usage INT DEFAULT 0 AFTER tokens"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT company_name FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN company_name VARCHAR(255) DEFAULT NULL"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT company_street_address FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN company_street_address VARCHAR(255) DEFAULT NULL"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT company_city FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN company_city VARCHAR(255) DEFAULT NULL"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT company_postal_code FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN company_postal_code VARCHAR(64) DEFAULT NULL"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT company_registration_number FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN company_registration_number VARCHAR(128) DEFAULT NULL"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT lora_id FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN lora_id VARCHAR(64) DEFAULT NULL"); } catch (Throwable) {}
            }
            try {
                $pdoBio->query("SELECT represent FROM users LIMIT 1");
            } catch (Throwable $e) {
                try { $pdoBio->exec("ALTER TABLE users ADD COLUMN represent TINYINT DEFAULT 0"); } catch (Throwable) {}
            }
            
            $stmt = $pdoBio->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $role = null;
            if (!$user) {
                if (is_string($username) && mh_username_is_reserved_prefix($username)) {
                    return false;
                }

                $groupsStr = is_array($groups) ? implode(';', $groups) : $groups;
                $unusedStr = is_string($unused) ? trim((string)$unused) : '';
                $hasSsoContext = (is_string($groupsStr) && trim($groupsStr) !== '') || ($unusedStr !== '' && strpos($unusedStr, '@') === false);
                if (!$hasSsoContext) {
                    return false;
                }

                $persona = 'MH-' . $username;
                $display = ($unusedStr !== '' && strpos($unusedStr, '@') === false) ? $unusedStr : ucfirst($username);
                $rf = null;
                $rl = null;
                $parts = preg_split('/\\s+/', (string)$display);
                $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
                if (count($parts) >= 2) {
                    $rf = (string)$parts[0];
                    $rl = (string)$parts[count($parts) - 1];
                } else {
                    $uParts = preg_split('/[._\\-]+/', (string)$username);
                    $uParts = is_array($uParts) ? array_values(array_filter(array_map('trim', $uParts), fn($p) => $p !== '')) : [];
                    if (count($uParts) >= 2) {
                        $rf = ucfirst((string)$uParts[0]);
                        $rl = ucfirst((string)$uParts[count($uParts) - 1]);
                    }
                }
                if (is_string($rf) && is_string($rl)) {
                    try {
                        if (function_exists('mh_validate_real_first_and_surname_strict')) {
                            mh_validate_real_first_and_surname_strict($rf, $rl);
                        }
                    } catch (Throwable $e) {
                        $rf = null;
                        $rl = null;
                    }
                }
                $tenantId = 'user:' . $username;
                $roleToStore = $ssoRole ?: 'Users';
                $groupsToStore = $groupsStr ? (string)$groupsStr : null;
                $permissionsToStore = null;
                if ($roleToStore === 'KripzMasters') {
                    $permissionsToStore = json_encode(['menus' => ['all'], 'submenus' => ['all']]);
                } else {
                    $permissionsToStore = json_encode(['menus' => [], 'submenus' => []]);
                }
                try {
                    if (function_exists('mh_ensure_user_real_name_schema')) {
                        mh_ensure_user_real_name_schema($pdoBio);
                    }
                    $ins = $pdoBio->prepare(
                        "INSERT INTO users (username, name, real_first_name, real_last_name, persona_name, role, tenant_id, permissions, groups, genesis_status, tokens, token_usage)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0)"
                    );
                    $ins->execute([$username, $display, $rf, $rl, $persona, $roleToStore, $tenantId, $permissionsToStore, $groupsToStore]);
                } catch (Throwable $e) {
                    error_log("Biometrics User Create Error: " . $e->getMessage());
                }
                $stmt = $pdoBio->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($user) {
                if (function_exists('mh_ensure_user_real_name_schema')) {
                    try { mh_ensure_user_real_name_schema($pdoBio); } catch (Throwable) {}
                }
                $expectedTenantId = function_exists('mh_normalize_tenant_id')
                    ? mh_normalize_tenant_id('user:' . $username)
                    : ('user:' . strtolower($username));
                $rawTenantId = isset($user['tenant_id']) ? trim((string)$user['tenant_id']) : '';
                $currentTenantId = function_exists('mh_normalize_tenant_id')
                    ? mh_normalize_tenant_id($rawTenantId)
                    : $rawTenantId;
                if ($rawTenantId !== '' && $expectedTenantId !== '' && $rawTenantId !== $expectedTenantId && stripos($rawTenantId, 'user:') === 0) {
                    $oldU = substr($rawTenantId, 5);
                    $oldU = is_string($oldU) ? trim($oldU) : '';
                    $sameUserCaseVariant = ($oldU !== '' && strcasecmp($oldU, $username) === 0);
                    $oldExists = false;
                    if ($oldU !== '' && !$sameUserCaseVariant) {
                        try {
                            $chk = $pdoBio->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
                            $chk->execute([$oldU]);
                            $oldExists = (bool)$chk->fetchColumn();
                        } catch (Throwable) {
                            $oldExists = false;
                        }
                    }
                    if (!$oldExists) {
                        try { mh_tenant_context_move($rawTenantId, $expectedTenantId); } catch (Throwable) {}
                        try { mh_tenant_storage_move($rawTenantId, $expectedTenantId); } catch (Throwable) {}
                        try {
                            $pdoBio->prepare("UPDATE users SET tenant_id = ? WHERE username = ?")->execute([$expectedTenantId, $username]);
                            $user['tenant_id'] = $expectedTenantId;
                            $currentTenantId = $expectedTenantId;
                        } catch (Throwable) {}
                    }
                } elseif (($rawTenantId === '' || $rawTenantId === null) && $expectedTenantId !== '') {
                    try {
                        $pdoBio->prepare("UPDATE users SET tenant_id = ? WHERE username = ?")->execute([$expectedTenantId, $username]);
                        $user['tenant_id'] = $expectedTenantId;
                        $currentTenantId = $expectedTenantId;
                    } catch (Throwable) {}
                }
                if ($ssoRole && isset($user['role']) && $user['role'] !== $ssoRole) {
                    $cur = (string)$user['role'];
                    $curIsKripz = (stripos($cur, 'kripzmaster') !== false);
                    $ssoIsKripz = (stripos($ssoRole, 'kripzmaster') !== false);
                    if ($ssoIsKripz || !$curIsKripz) {
                        try {
                            $upd = $pdoBio->prepare("UPDATE users SET role = ? WHERE username = ?");
                            $upd->execute([$ssoRole, $username]);
                            $user['role'] = $ssoRole;
                        } catch (Throwable) {}
                    }
                }

                $updates = [];
                $params = [];
                if (is_string($groupsStr) && trim($groupsStr) !== '' && (($user['groups'] ?? null) !== (string)$groupsStr)) {
                    $updates[] = "groups = ?";
                    $params[] = (string)$groupsStr;
                    $user['groups'] = (string)$groupsStr;
                }
                if ($updates) {
                    $params[] = $username;
                    try {
                        $pdoBio->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE username = ?")->execute($params);
                    } catch (Throwable) {}
                }
                if ($emailHint !== '' && (!isset($user['email']) || trim((string)$user['email']) === '')) {
                    try {
                        $pdoBio->prepare("UPDATE users SET email = ? WHERE username = ?")->execute([$emailHint, $username]);
                        $user['email'] = $emailHint;
                    } catch (Throwable) {}
                }
                
                $rawRole = (string)($user['role'] ?? $user['roles'] ?? '');
                $role = (stripos($rawRole, 'kripzmaster') !== false) ? 'KripzMasters' : 'Users';
                
                $_SESSION['mh_auth_role'] = $role;
                $_SESSION['mh_auth_persona'] = $user['persona_name'] ?? $user['name'] ?? $username;
                $_SESSION['mh_auth_display'] = isset($user['persona_name']) && !empty($user['persona_name']) ? $user['persona_name'] : ($user['name'] ?: $username);
                $_SESSION['mh_auth_permissions'] = $user['permissions'] ?? null;
                $_SESSION['mh_user_internal_id'] = $user['id'] ?? null;
                $_SESSION['mh_genesis_status'] = $user['genesis_status'] ?? 0;
                $_SESSION['mh_tenant_id'] = $currentTenantId !== '' ? $currentTenantId : ($user['tenant_id'] ?? null);
                $_SESSION['mh_persona_tenant_id'] = (isset($user['persona_name']) && !empty($user['persona_name'])) ? ('persona:' . $user['persona_name']) : null;
                $_SESSION['mh_user_name'] = $user['name'] ?? null;
                $_SESSION['mh_user_real_first_name'] = $user['real_first_name'] ?? null;
                $_SESSION['mh_user_real_last_name'] = $user['real_last_name'] ?? null;
                $_SESSION['mh_company_name'] = $user['company_name'] ?? null;
                $_SESSION['mh_company_street_address'] = $user['company_street_address'] ?? null;
                $_SESSION['mh_company_city'] = $user['company_city'] ?? null;
                $_SESSION['mh_company_postal_code'] = $user['company_postal_code'] ?? null;
                $_SESSION['mh_company_registration_number'] = $user['company_registration_number'] ?? null;
                $_SESSION['mh_auth_email'] = (isset($user['email']) && filter_var(trim((string)$user['email']), FILTER_VALIDATE_EMAIL))
                    ? trim((string)$user['email'])
                    : ($emailHint !== '' ? $emailHint : null);
                
                $tok = null;
                if (function_exists('mh_get_token_balance')) {
                    $tok = mh_get_token_balance($username);
                }
                $_SESSION['tokens'] = is_int($tok) ? $tok : ($user['tokens'] ?? 0);
                $_SESSION['token_usage'] = $user['token_usage'] ?? 0;
                if (function_exists('mh_refresh_session_token_balance')) {
                    try { mh_refresh_session_token_balance($username, 10); } catch (Throwable) {}
                }
                if (isset($user['device_id']) && is_string($user['device_id']) && trim((string)$user['device_id']) !== '') {
                    $_SESSION['mh_device_id'] = (string)$user['device_id'];
                }
                if (isset($user['groups']) && is_string($user['groups']) && trim($user['groups']) !== '') {
                    $_SESSION['mh_auth_groups'] = trim((string)$user['groups']);
                }

                if (!empty($_SESSION['mh_tenant_id'])) {
                    try {
                        if (function_exists('mh_provision_tenant_storage')) {
                            mh_provision_tenant_storage((string)$_SESSION['mh_tenant_id']);
                        }
                        if (function_exists('mh_apply_tenant_context')) {
                            mh_apply_tenant_context((string)$_SESSION['mh_tenant_id']);
                        }
                    } catch (Throwable $e) {
                        error_log('Tenant context apply skipped: ' . $e->getMessage());
                    }
                }

                try {
                    if (function_exists('mh_persona_registry_pdo') && function_exists('mh_user_directory_upsert')) {
                        $pdoReg = mh_persona_registry_pdo();
                        $rf = isset($user['real_first_name']) ? trim((string)$user['real_first_name']) : '';
                        $rl = isset($user['real_last_name']) ? trim((string)$user['real_last_name']) : '';
                        $nameForDir = trim(($rf !== '' || $rl !== '') ? ($rf . ' ' . $rl) : (isset($user['name']) ? (string)$user['name'] : ''));
                        mh_user_directory_upsert(
                            $pdoReg,
                            $username,
                            $nameForDir,
                            $rf !== '' ? $rf : null,
                            $rl !== '' ? $rl : null,
                            isset($user['persona_name']) ? (string)$user['persona_name'] : null,
                            isset($user['email']) ? (string)$user['email'] : null
                        );
                    }
                } catch (Throwable) {}

                $missing = [];
                $rf = isset($user['real_first_name']) ? trim((string)$user['real_first_name']) : '';
                $rl = isset($user['real_last_name']) ? trim((string)$user['real_last_name']) : '';
                $hasRealParts = false;
                if ($rf !== '' && $rl !== '' && mb_strlen($rf) >= 2 && mb_strlen($rl) >= 2) {
                    try {
                        if (function_exists('mh_validate_real_first_and_surname_strict')) {
                            mh_validate_real_first_and_surname_strict($rf, $rl);
                        }
                        $hasRealParts = true;
                    } catch (Throwable $e) {
                        $hasRealParts = false;
                    }
                }
                if (!$hasRealParts) {
                    $nameVal = isset($user['name']) ? trim((string)$user['name']) : '';
                    $parts = $nameVal !== '' ? preg_split('/\s+/', $nameVal) : [];
                    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
                    if (count($parts) >= 2) {
                        $rf2 = (string)$parts[0];
                        $rl2 = (string)$parts[count($parts) - 1];
                        if (mb_strlen($rf2) >= 2 && mb_strlen($rl2) >= 2) {
                            try {
                                if (function_exists('mh_validate_real_first_and_surname_strict')) {
                                    mh_validate_real_first_and_surname_strict($rf2, $rl2);
                                }
                                $hasRealParts = true;
                            } catch (Throwable $e) {
                                $hasRealParts = false;
                            }
                        }
                    }
                }
                if (!$hasRealParts) $missing[] = 'Real name and surname';
                $personaVal = isset($user['persona_name']) ? trim((string)$user['persona_name']) : '';
                if ($personaVal === '') {
                    $missing[] = 'Persona name';
                }
                $loraId = isset($user['lora_id']) ? trim((string)$user['lora_id']) : '';
                if ($loraId !== '') {
                    $cn = isset($user['company_name']) ? trim((string)$user['company_name']) : '';
                    $cs = isset($user['company_street_address']) ? trim((string)$user['company_street_address']) : '';
                    $cc = isset($user['company_city']) ? trim((string)$user['company_city']) : '';
                    $cp = isset($user['company_postal_code']) ? trim((string)$user['company_postal_code']) : '';
                    $cr = isset($user['company_registration_number']) ? trim((string)$user['company_registration_number']) : '';
                    if ($cn === '') $missing[] = 'Company name';
                    if ($cs === '') $missing[] = 'Company street address';
                    if ($cc === '') $missing[] = 'Company city';
                    if ($cp === '') $missing[] = 'Company postal code';
                    if ($cr === '') $missing[] = 'Company registration number';
                    $rep = isset($user['represent']) ? (int)$user['represent'] : 0;
                    if ($rep !== 1) $missing[] = 'Representative confirmation';
                }
                $_SESSION['mh_profile_missing_fields'] = $missing;
                
                return true;
            }
        } catch (Exception $e) {
            error_log("Biometrics Load Error: " . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('mh_auth_load_user_context')) {
    function mh_auth_load_user_context(mixed $username, mixed $groups = null, mixed $unused = null): bool {
        return mh_load_biometrics_user($username, $groups, $unused);
    }
}

require_once __DIR__ . '/tokenomics.php';

if (!function_exists('mh_ensure_user_real_name_schema')) {
    function mh_ensure_user_real_name_schema(PDO $pdoBio): void {
        try { $pdoBio->query("SELECT real_first_name FROM users LIMIT 1"); } catch (Throwable) { try { $pdoBio->exec("ALTER TABLE users ADD COLUMN real_first_name VARCHAR(64) DEFAULT NULL AFTER name"); } catch (Throwable) {} }
        try { $pdoBio->query("SELECT real_last_name FROM users LIMIT 1"); } catch (Throwable) { try { $pdoBio->exec("ALTER TABLE users ADD COLUMN real_last_name VARCHAR(64) DEFAULT NULL AFTER real_first_name"); } catch (Throwable) {} }
        try {
            $hasUnique = false;
            $idx = $pdoBio->query("SHOW INDEX FROM users WHERE Column_name = 'username' AND Non_unique = 0");
            if ($idx) {
                $rows = $idx->fetchAll(PDO::FETCH_ASSOC);
                $hasUnique = is_array($rows) && count($rows) > 0;
            }
            if (!$hasUnique) {
                try {
                    $pdoBio->exec("ALTER TABLE users ADD UNIQUE KEY uniq_users_username (username)");
                    $hasUnique = true;
                } catch (Throwable $e) {
                    try {
                        $dupsStmt = $pdoBio->query("SELECT username FROM users GROUP BY username HAVING COUNT(*) > 1 LIMIT 500");
                        $dups = $dupsStmt ? $dupsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                        if (is_array($dups)) {
                            foreach ($dups as $uname) {
                                $uname = is_string($uname) ? trim($uname) : '';
                                if ($uname === '') continue;
                                $st = $pdoBio->prepare("SELECT id, username, name, real_first_name, real_last_name, persona_name, tenant_id, role, permissions, `groups`, updated_at, created_at FROM users WHERE username = ? ORDER BY updated_at DESC, created_at DESC, id DESC");
                                $st->execute([$uname]);
                                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                                if (!is_array($rows) || count($rows) < 2) continue;
                                $best = null;
                                $bestScore = -1;
                                foreach ($rows as $row) {
                                    $score = 0;
                                    foreach (['real_first_name', 'real_last_name', 'persona_name', 'tenant_id', 'role', 'permissions', 'groups', 'name'] as $k) {
                                        $v = isset($row[$k]) ? trim((string)$row[$k]) : '';
                                        if ($v !== '') $score++;
                                    }
                                    if ($score > $bestScore) {
                                        $best = $row;
                                        $bestScore = $score;
                                    }
                                }
                                $keepId = isset($best['id']) ? (int)$best['id'] : 0;
                                if ($keepId <= 0) continue;
                                $rfKeep = isset($best['real_first_name']) ? trim((string)$best['real_first_name']) : '';
                                $rlKeep = isset($best['real_last_name']) ? trim((string)$best['real_last_name']) : '';
                                $rfFill = $rfKeep;
                                $rlFill = $rlKeep;
                                foreach ($rows as $row) {
                                    $id = isset($row['id']) ? (int)$row['id'] : 0;
                                    if ($id === $keepId) continue;
                                    if ($rfFill === '' && isset($row['real_first_name']) && trim((string)$row['real_first_name']) !== '') {
                                        $rfFill = trim((string)$row['real_first_name']);
                                    }
                                    if ($rlFill === '' && isset($row['real_last_name']) && trim((string)$row['real_last_name']) !== '') {
                                        $rlFill = trim((string)$row['real_last_name']);
                                    }
                                }
                                if ($rfFill !== $rfKeep || $rlFill !== $rlKeep) {
                                    try {
                                        $pdoBio->prepare("UPDATE users SET real_first_name = ?, real_last_name = ? WHERE id = ?")->execute([$rfFill !== '' ? $rfFill : null, $rlFill !== '' ? $rlFill : null, $keepId]);
                                    } catch (Throwable) {}
                                }
                                try {
                                    $pdoBio->prepare("DELETE FROM users WHERE username = ? AND id <> ?")->execute([$uname, $keepId]);
                                } catch (Throwable) {}
                            }
                        }
                    } catch (Throwable) {}
                    try {
                        $pdoBio->exec("ALTER TABLE users ADD UNIQUE KEY uniq_users_username (username)");
                        $hasUnique = true;
                    } catch (Throwable) {}
                }
            }
        } catch (Throwable) {}
        try {
            $stmt = $pdoBio->query("SELECT username, name, real_first_name, real_last_name FROM users WHERE (real_first_name IS NULL OR real_first_name = '' OR real_last_name IS NULL OR real_last_name = '') AND name IS NOT NULL AND name <> '' LIMIT 500");
            if ($stmt) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $u = isset($r['username']) ? trim((string)$r['username']) : '';
                    if ($u === '') continue;
                    $rf = isset($r['real_first_name']) ? trim((string)$r['real_first_name']) : '';
                    $rl = isset($r['real_last_name']) ? trim((string)$r['real_last_name']) : '';
                    if ($rf !== '' && $rl !== '') continue;
                    $name = isset($r['name']) ? trim((string)$r['name']) : '';
                    $parts = $name !== '' ? preg_split('/\s+/', $name) : [];
                    $parts = is_array($parts) ? array_values(array_filter(array_map('trim', $parts), fn($p) => $p !== '')) : [];
                    if (count($parts) < 2) continue;
                    $rf2 = $rf !== '' ? $rf : (string)$parts[0];
                    $rl2 = $rl !== '' ? $rl : (string)$parts[count($parts) - 1];
                    try {
                        if (function_exists('mh_validate_real_first_and_surname_strict')) {
                            mh_validate_real_first_and_surname_strict($rf2, $rl2);
                        }
                        $pdoBio->prepare("UPDATE users SET real_first_name = COALESCE(NULLIF(real_first_name,''), ?), real_last_name = COALESCE(NULLIF(real_last_name,''), ?) WHERE username = ?")->execute([$rf2, $rl2, $u]);
                    } catch (Throwable) {}
                }
            }
        } catch (Throwable) {}
    }
}

if (!function_exists('mh_debit_tokens')) {
    function mh_debit_tokens(mixed $username, mixed $amount, ?string $reason = null, mixed $meta = null): bool {
        try {
            $username = is_string($username) ? trim($username) : '';
            if ($username === '') {
                return false;
            }
            $amount = (int)$amount;
            if ($amount <= 0) {
                return true;
            }

            if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) {
                $p = __DIR__ . '/tokenomics.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_ensure_schema')) {
                return false;
            }
            $pdoTok = call_user_func('mh_tokenomics_get_tokenomics_pdo');
            call_user_func('mh_tokenomics_ensure_schema', $pdoTok);

            if (function_exists('mh_tokenomics_debit_utility_tokens_exact')) {
                $ok = (bool)call_user_func('mh_tokenomics_debit_utility_tokens_exact', $pdoTok, $username, $amount, $reason, $meta);
                if (!$ok) {
                    return false;
                }
            } else {
                if (!function_exists('mh_tokenomics_seed_utility_token') || !function_exists('mh_tokenomics_apply_delta')) {
                    return false;
                }
                $utilityClassId = call_user_func('mh_tokenomics_seed_utility_token', $pdoTok);
                if ($utilityClassId < 1) return false;
                $ok = call_user_func('mh_tokenomics_apply_delta', $pdoTok, $username, $utilityClassId, -$amount, is_string($reason) ? $reason : null, null, $meta);
                if (!$ok) return false;
            }

            return true;
        } catch (Throwable $e) {
            error_log("Token Debit Error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mh_auth_user_store_pdo')) {
    function mh_auth_user_store_pdo(): PDO {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getConnectionById')) {
            throw new RuntimeException('database_module_unavailable');
        }
        $pdo = database_getConnectionById('biometrics');
        if (!$pdo instanceof PDO) {
            if (is_object($pdo) && property_exists($pdo, 'pdo') && $pdo->pdo instanceof PDO) {
                $pdo = $pdo->pdo;
            } elseif (is_array($pdo) && isset($pdo['pdo']) && $pdo['pdo'] instanceof PDO) {
                $pdo = $pdo['pdo'];
            }
        }
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('auth_user_store_unavailable');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}

if (!function_exists('mh_auth_resolve_username_from_login_id')) {
    function mh_auth_resolve_username_from_login_id(string $loginId): string {
        $raw = trim($loginId);
        if ($raw === '') {
            return $raw;
        }

        $candidate = $raw;
        $personaCandidate = '';
        if (stripos($raw, 'user:') === 0) {
            $candidate = trim(substr($raw, strlen('user:')));
        } elseif (stripos($raw, 'persona:') === 0) {
            $personaCandidate = trim(substr($raw, strlen('persona:')));
            $candidate = $raw;
        }
        if ($candidate === '') {
            return $raw;
        }
        if ($personaCandidate === '') {
            $personaCandidate = $candidate;
        }

        try {
            $pdoAuth = mh_auth_user_store_pdo();
            if (ctype_digit($candidate)) {
                $stmt = $pdoAuth->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$candidate]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['username'])) {
                    return (string)$row['username'];
                }
            }

            foreach ([
                ["SELECT username FROM users WHERE username = ? LIMIT 1", $candidate],
                ["SELECT username FROM users WHERE persona_name = ? LIMIT 1", $personaCandidate],
                ["SELECT username FROM users WHERE tenant_id = ? LIMIT 1", $raw],
            ] as [$sql, $value]) {
                $stmt = $pdoAuth->prepare($sql);
                $stmt->execute([$value]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['username'])) {
                    return (string)$row['username'];
                }
            }
        } catch (Throwable) {
        }

        try {
            $pdoReg = mh_persona_registry_pdo();
            if (function_exists('mh_user_directory_get')) {
                $row = mh_user_directory_get($pdoReg, $candidate);
                if (is_array($row) && !empty($row['username'])) {
                    return (string)$row['username'];
                }
            }

            $stmt = $pdoReg->prepare("SELECT username FROM mh_user_directory WHERE persona_name = ? LIMIT 1");
            $stmt->execute([$personaCandidate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['username'])) {
                return (string)$row['username'];
            }
        } catch (Throwable) {
        }

        return $candidate;
    }
}

if (!function_exists('mh_credit_tokens')) {
    function mh_credit_tokens(mixed $username, mixed $amount, ?string $reason = null, mixed $meta = null): bool {
        try {
            $username = is_string($username) ? trim($username) : '';
            if ($username === '') {
                return false;
            }
            $amount = (int)$amount;
            if ($amount <= 0) {
                return true;
            }

            if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) {
                $p = __DIR__ . '/tokenomics.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_ensure_schema')) {
                return false;
            }
            $pdoTok = call_user_func('mh_tokenomics_get_tokenomics_pdo');
            call_user_func('mh_tokenomics_ensure_schema', $pdoTok);
            if (!function_exists('mh_tokenomics_seed_utility_token') || !function_exists('mh_tokenomics_apply_delta')) {
                return false;
            }
            $utilityClassId = call_user_func('mh_tokenomics_seed_utility_token', $pdoTok);
            if ($utilityClassId < 1) return false;
            $ok = call_user_func('mh_tokenomics_apply_delta', $pdoTok, $username, $utilityClassId, $amount, is_string($reason) ? $reason : null, null, $meta);
            if (!$ok) return false;
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] === $username) {
                $bal = function_exists('mh_get_token_balance') ? mh_get_token_balance($username) : null;
                if (is_int($bal)) {
                    $_SESSION['tokens'] = $bal;
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log("Token Credit Error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mh_transfer_tokens')) {
    function mh_transfer_tokens(mixed $fromUser, mixed $toUser, mixed $amount, ?string $reason = null, mixed $meta = null): bool {
        try {
            $fromUser = is_string($fromUser) ? trim($fromUser) : '';
            $toUser = is_string($toUser) ? trim($toUser) : '';
            $amount = (int)$amount;
            if ($fromUser === '' || $toUser === '' || $amount <= 0) {
                return false;
            }
            if ($fromUser === $toUser) {
                return true;
            }
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) {
                $p = __DIR__ . '/tokenomics.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_transfer_utility_tokens_exact')) {
                return false;
            }
            $pdoTok = call_user_func('mh_tokenomics_get_tokenomics_pdo');
            $ref = bin2hex(random_bytes(16));
            $ok = call_user_func('mh_tokenomics_transfer_utility_tokens_exact', $pdoTok, $fromUser, $toUser, $amount, is_string($reason) ? $reason : null, $ref, $meta);
            if (!$ok) return false;
            if (session_status() === PHP_SESSION_ACTIVE) {
                foreach ([$fromUser, $toUser] as $cand) {
                    if (isset($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] === $cand && function_exists('mh_get_token_balance')) {
                        $bal = mh_get_token_balance($cand);
                        if (is_int($bal)) {
                            $_SESSION['tokens'] = $bal;
                        }
                    }
                }
            }
            return true;
        } catch (Throwable $e) {
            error_log("Token Transfer Error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mh_get_token_balance')) {
    function mh_get_token_balance(mixed $username): ?int {
        try {
            $username = is_string($username) ? trim($username) : '';
            if ($username === '') {
                return null;
            }

            if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) {
                $p = __DIR__ . '/tokenomics.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_ensure_schema')) {
                return null;
            }
            $pdoTok = call_user_func('mh_tokenomics_get_tokenomics_pdo');
            call_user_func('mh_tokenomics_ensure_schema', $pdoTok);

            if (function_exists('mh_tokenomics_get_utility_balance')) {
                $bal = call_user_func('mh_tokenomics_get_utility_balance', $pdoTok, $username);
                if (is_int($bal)) {
                    return $bal;
                }
            }
            return null;
        } catch (Throwable $e) {
            error_log("Token Balance Error: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mh_refresh_session_token_balance')) {
    function mh_refresh_session_token_balance(string $username, int $maxAgeSeconds = 30): ?int {
        if ($username === '') return null;
        if (session_status() !== PHP_SESSION_ACTIVE) return null;
        $last = isset($_SESSION['mh_tokens_checked_at']) ? (int)$_SESSION['mh_tokens_checked_at'] : 0;
        if ($last > 0 && (time() - $last) < max(1, $maxAgeSeconds)) {
            return isset($_SESSION['tokens']) ? (int)$_SESSION['tokens'] : null;
        }
        $_SESSION['mh_tokens_checked_at'] = time();
        $bal = null;
        try {
            if (function_exists('mh_get_token_balance')) {
                $bal = mh_get_token_balance($username);
            }
        } catch (Throwable $e) {
            $bal = null;
        }
        if (is_int($bal)) {
            $_SESSION['tokens'] = $bal;
            return $bal;
        }
        return isset($_SESSION['tokens']) ? (int)$_SESSION['tokens'] : null;
    }
}

if (!function_exists('mh_validate_real_first_and_surname_strict')) {
    function mh_validate_real_first_and_surname_strict(string $first, string $surname): void {
        $first = trim($first);
        $surname = trim($surname);
        if ($first === '') throw new Exception('Real name is required.');
        if ($surname === '') throw new Exception('Real surname is required.');
        if (strpos($first, '@') !== false || strpos($surname, '@') !== false) throw new Exception('Real name/surname cannot contain "@".');
        $firstClean = preg_replace("/[^a-zA-Z\\-']/u", '', $first);
        $surnameClean = preg_replace("/[^a-zA-Z\\-']/u", '', $surname);
        if (!is_string($firstClean) || strlen($firstClean) < 2) throw new Exception('Real name must be at least 2 characters.');
        if (!is_string($surnameClean) || strlen($surnameClean) < 2) throw new Exception('Real surname must be at least 2 characters.');
        if (strcasecmp($firstClean, $surnameClean) === 0) throw new Exception('Real name and surname cannot be the same.');
    }
}

if (!function_exists('mh_tenant_safe_id')) {
    function mh_tenant_safe_id(string $tenantId): string {
        $safe = preg_replace('/[^a-zA-Z0-9:_-]/', '_', $tenantId);
        $safe = str_replace(':', '_', (string)$safe);
        $safe = preg_replace('/_+/', '_', (string)$safe);
        return trim((string)$safe, '_');
    }
}

if (!function_exists('mh_tenant_context_move')) {
    function mh_tenant_context_move(string $oldTenantId, string $newTenantId): void {
        $oldTenantId = trim($oldTenantId);
        $newTenantId = trim($newTenantId);
        if ($oldTenantId === '' || $newTenantId === '' || $oldTenantId === $newTenantId) return;
        $ctxFile = '/data/config/tenant-contexts.json';
        if (!is_file($ctxFile)) return;
        $raw = (string)@file_get_contents($ctxFile);
        $ctx = json_decode($raw, true);
        if (!is_array($ctx)) return;
        if (!isset($ctx[$oldTenantId])) return;
        if (!isset($ctx[$newTenantId])) {
            $ctx[$newTenantId] = $ctx[$oldTenantId];
        }
        unset($ctx[$oldTenantId]);
        @file_put_contents($ctxFile, json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }
}

if (!function_exists('mh_tenant_storage_move')) {
    function mh_tenant_storage_move(string $oldTenantId, string $newTenantId): void {
        $oldTenantId = trim($oldTenantId);
        $newTenantId = trim($newTenantId);
        if ($oldTenantId === '' || $newTenantId === '' || $oldTenantId === $newTenantId) return;
        $oldSafe = mh_tenant_safe_id($oldTenantId);
        $newSafe = mh_tenant_safe_id($newTenantId);
        if ($oldSafe !== '' && $newSafe !== '' && $oldSafe !== $newSafe) {
            $oldDir = '/data/tenants/' . $oldSafe;
            $newDir = '/data/tenants/' . $newSafe;
            if (is_dir($oldDir) && !is_dir($newDir)) {
                @rename($oldDir, $newDir);
            }
        }
        $suffixOld = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $oldTenantId);
        $suffixNew = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $newTenantId);
        if ($suffixOld !== '' && $suffixNew !== '' && $suffixOld !== $suffixNew) {
            $vo = '/vector/tenant_' . $suffixOld;
            $vn = '/vector/tenant_' . $suffixNew;
            if (is_dir($vo) && !is_dir($vn)) @rename($vo, $vn);
            $go = '/graph/tenant_' . $suffixOld;
            $gn = '/graph/tenant_' . $suffixNew;
            if (is_dir($go) && !is_dir($gn)) @rename($go, $gn);
        }
    }
}

if (!function_exists('mh_tokenomics_migrate_username')) {
    function mh_tokenomics_migrate_username(string $oldUsername, string $newUsername): void {
        $oldUsername = trim($oldUsername);
        $newUsername = trim($newUsername);
        if ($oldUsername === '' || $newUsername === '' || $oldUsername === $newUsername) return;
        try {
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) {
                $p = __DIR__ . '/tokenomics.php';
                if (is_file($p)) {
                    require_once $p;
                }
            }
            if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_ensure_schema')) {
                return;
            }
            $pdoTok = call_user_func('mh_tokenomics_get_tokenomics_pdo');
            call_user_func('mh_tokenomics_ensure_schema', $pdoTok);
            $oldTenantId = 'user:' . $oldUsername;
            $newTenantId = 'user:' . $newUsername;
            $pdoTok->prepare("UPDATE mh_asset_ledger SET tenant_id = ?, username = ? WHERE tenant_id = ? AND username = ?")->execute([$newTenantId, $newUsername, $oldTenantId, $oldUsername]);
            $pdoTok->prepare("UPDATE mh_asset_transactions SET tenant_id = ?, username = ? WHERE tenant_id = ? AND username = ?")->execute([$newTenantId, $newUsername, $oldTenantId, $oldUsername]);
        } catch (Throwable) {}
    }
}

if (!function_exists('mh_registration_norm_letters')) {
    function mh_registration_norm_letters(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = preg_replace("/[^a-z\\-']/u", '', $value);
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('mh_registration_norm_compact')) {
    function mh_registration_norm_compact(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
        $value = preg_replace('/\\s+/', '', $value);
        $value = preg_replace('/[^a-z0-9]+/i', '', (string)$value);
        return is_string($value) ? $value : '';
    }
}

if (!function_exists('mh_registration_looks_like_fake_name')) {
    function mh_registration_looks_like_fake_name(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') return 'empty';
        if (preg_match('/\\d/', $raw)) return 'digits_not_allowed';
        if (strpos($raw, '@') !== false) return 'email_not_allowed';

        $compact = mh_registration_norm_compact($raw);
        if ($compact === '') return 'invalid_characters';
        if (preg_match('/(.)\\1\\1\\1/i', $compact)) return 'repeated_characters';

        $letters = preg_replace('/[^a-z]+/i', '', $compact);
        $letters = is_string($letters) ? strtolower($letters) : '';
        $len = strlen($letters);
        if ($len >= 6) {
            $uniq = count(array_unique(str_split($letters)));
            if ($uniq <= 2) return 'low_character_diversity';
        }

        $badSeq = [
            '012345', '12345', '23456', '34567', '45678', '56789',
            'qwerty', 'asdf', 'zxcv', 'password',
        ];
        foreach ($badSeq as $s) {
            if ($s !== '' && strpos($compact, $s) !== false) return 'obvious_sequence';
        }

        $word = preg_replace('/[^a-z]+/i', '', $compact);
        $word = is_string($word) ? strtolower($word) : '';
        $blocked = [
            'test', 'testing', 'tester', 'demo', 'sample', 'asdf', 'admin', 'master',
            'unknown', 'null', 'na', 'n/a', 'foo', 'bar',
        ];
        if ($word !== '') {
            foreach ($blocked as $b) {
                $b2 = preg_replace('/[^a-z]+/i', '', (string)$b);
                $b2 = is_string($b2) ? strtolower($b2) : '';
                if ($b2 !== '' && ($word === $b2 || strpos($word, $b2) !== false)) return 'placeholder_word';
            }
        }

        return null;
    }
}

if (!function_exists('mh_registration_validate_real_first_last_for_registration')) {
    function mh_registration_validate_real_first_last_for_registration(string $first, string $last): void
    {
        $first = trim($first);
        $last = trim($last);
        if ($first === '') throw new Exception('Real name is required.');
        if ($last === '') throw new Exception('Real surname is required.');

        $why1 = mh_registration_looks_like_fake_name($first);
        if ($why1 !== null) throw new Exception('Real name is invalid.');
        $why2 = mh_registration_looks_like_fake_name($last);
        if ($why2 !== null) throw new Exception('Real surname is invalid.');

        $firstClean = mh_registration_norm_letters($first);
        $lastClean = mh_registration_norm_letters($last);
        if ($firstClean === '' || strlen($firstClean) < 2) throw new Exception('Real name must be at least 2 characters.');
        if ($lastClean === '' || strlen($lastClean) < 2) throw new Exception('Real surname must be at least 2 characters.');
        if (strcasecmp($firstClean, $lastClean) === 0) throw new Exception('Real name and surname cannot be the same.');
    }
}

if (!function_exists('mh_registration_ensure_policy_schema')) {
    function mh_registration_ensure_policy_schema(PDO $pdoBio): void
    {
        $pdoBio->exec("CREATE TABLE IF NOT EXISTS mh_registration_policy_rules (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(32) NOT NULL,
            rule_type VARCHAR(32) NOT NULL,
            pattern TEXT NOT NULL,
            action VARCHAR(16) NOT NULL DEFAULT 'reject',
            enabled TINYINT NOT NULL DEFAULT 1,
            created_by VARCHAR(255) NULL,
            updated_by VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_scope_enabled (scope, enabled),
            KEY idx_rule_type (rule_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { $pdoBio->query("SELECT updated_by FROM mh_registration_policy_rules LIMIT 1"); } catch (Throwable) { try { $pdoBio->exec("ALTER TABLE mh_registration_policy_rules ADD COLUMN updated_by VARCHAR(255) NULL AFTER created_by"); } catch (Throwable) {} }

        $pdoBio->exec("CREATE TABLE IF NOT EXISTS mh_registration_rate_limits (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            key_hash CHAR(64) NOT NULL,
            bucket VARCHAR(32) NOT NULL,
            key_value VARCHAR(255) NOT NULL,
            window_start INT NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_seen INT NOT NULL,
            blocked_until INT NULL,
            UNIQUE KEY uniq_key_hash (key_hash),
            KEY idx_bucket_seen (bucket, last_seen),
            KEY idx_bucket_blocked (bucket, blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdoBio->exec("CREATE TABLE IF NOT EXISTS mh_registration_review_queue (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NULL,
            ip_address VARCHAR(45) NULL,
            device_fingerprint VARCHAR(64) NULL,
            scope VARCHAR(32) NOT NULL,
            reason VARCHAR(64) NOT NULL,
            raw_value VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created_at (created_at),
            KEY idx_scope (scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('mh_registration_seed_default_policy_rules')) {
    function mh_registration_seed_default_policy_rules(PDO $pdoBio): void
    {
        mh_registration_ensure_policy_schema($pdoBio);
        $count = 0;
        try { $count = (int)$pdoBio->query("SELECT COUNT(*) FROM mh_registration_policy_rules")->fetchColumn(); } catch (Throwable) { $count = 0; }
        if ($count > 0) return;

        $defaults = [
            ['real_first_name', 'blocked_word', 'test'],
            ['real_first_name', 'blocked_word', 'testing'],
            ['real_first_name', 'blocked_word', 'tester'],
            ['real_first_name', 'blocked_word', 'demo'],
            ['real_first_name', 'blocked_word', 'sample'],
            ['real_first_name', 'blocked_word', 'asdf'],
            ['real_first_name', 'blocked_word', 'admin'],
            ['real_first_name', 'blocked_word', 'master'],
            ['real_first_name', 'blocked_word', 'unknown'],
            ['real_last_name', 'blocked_word', 'test'],
            ['real_last_name', 'blocked_word', 'testing'],
            ['real_last_name', 'blocked_word', 'tester'],
            ['real_last_name', 'blocked_word', 'demo'],
            ['real_last_name', 'blocked_word', 'sample'],
            ['real_last_name', 'blocked_word', 'asdf'],
            ['real_last_name', 'blocked_word', 'admin'],
            ['real_last_name', 'blocked_word', 'master'],
            ['real_last_name', 'blocked_word', 'unknown'],
        ];
        $ins = $pdoBio->prepare("INSERT INTO mh_registration_policy_rules (scope, rule_type, pattern, action, enabled, created_by) VALUES (?, ?, ?, 'reject', 1, 'system')");
        foreach ($defaults as $r) {
            try { $ins->execute([$r[0], $r[1], $r[2]]); } catch (Throwable) {}
        }
    }
}

if (!function_exists('mh_identity_name_tokens')) {
    function mh_identity_name_tokens(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [];
        if (strpos($name, ',') !== false) {
            $parts = explode(',', $name, 2);
            $last = trim((string)($parts[0] ?? ''));
            $rest = trim((string)($parts[1] ?? ''));
            if ($last !== '' && $rest !== '') {
                $name = $rest . ' ' . $last;
            }
        }
        $name = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
        $name = preg_replace('/[^a-z\\s]+/u', ' ', (string)$name);
        $name = preg_replace('/\\s+/', ' ', (string)$name);
        $name = trim((string)$name);
        if ($name === '') return [];
        $parts = explode(' ', $name);
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '' || strlen($p) < 2) continue;
            $out[] = $p;
        }
        return $out;
    }
}

if (!function_exists('mh_identity_billing_name_matches_user')) {
    function mh_identity_billing_name_matches_user(?string $billingName, ?string $realFirst, ?string $realLast): ?bool
    {
        $billingName = is_string($billingName) ? trim($billingName) : '';
        $realFirst = is_string($realFirst) ? trim($realFirst) : '';
        $realLast = is_string($realLast) ? trim($realLast) : '';
        if ($billingName === '' || $realFirst === '' || $realLast === '') return null;

        $bTokens = mh_identity_name_tokens($billingName);
        if (count($bTokens) < 2) return null;
        $bf = (string)$bTokens[0];
        $bl = (string)$bTokens[count($bTokens) - 1];

        $fTokens = mh_identity_name_tokens($realFirst);
        $lTokens = mh_identity_name_tokens($realLast);
        $ef = count($fTokens) > 0 ? (string)$fTokens[0] : mh_registration_norm_letters($realFirst);
        $el = count($lTokens) > 0 ? (string)$lTokens[count($lTokens) - 1] : mh_registration_norm_letters($realLast);
        $ef = trim((string)$ef);
        $el = trim((string)$el);
        if ($ef === '' || $el === '') return null;

        return (strcasecmp($bf, $ef) === 0) && (strcasecmp($bl, $el) === 0);
    }
}

if (!function_exists('mh_registration_policy_evaluate')) {
    function mh_registration_policy_evaluate(PDO $pdoBio, string $scope, string $rawValue): ?array
    {
        $scope = trim($scope);
        $rawValue = trim($rawValue);
        if ($scope === '' || $rawValue === '') return null;

        mh_registration_seed_default_policy_rules($pdoBio);

        $compact = mh_registration_norm_compact($rawValue);
        $letters = preg_replace('/[^a-z]+/i', '', $compact);
        $letters = is_string($letters) ? strtolower($letters) : '';

        try {
            $stmt = $pdoBio->prepare("SELECT rule_type, pattern, action FROM mh_registration_policy_rules WHERE scope = ? AND enabled = 1 ORDER BY id ASC");
            $stmt->execute([$scope]);
            $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rules = [];
        }
        foreach ($rules as $r) {
            $type = strtolower(trim((string)($r['rule_type'] ?? '')));
            $pattern = trim((string)($r['pattern'] ?? ''));
            $action = strtolower(trim((string)($r['action'] ?? 'reject')));
            if ($pattern === '') continue;

            if ($type === 'blocked_word') {
                $p = preg_replace('/[^a-z]+/i', '', $pattern);
                $p = is_string($p) ? strtolower($p) : '';
                if ($p !== '' && ($letters === $p || strpos($letters, $p) !== false)) {
                    return ['action' => $action ?: 'reject', 'reason' => 'policy_blocked_word', 'pattern' => $pattern];
                }
            } elseif ($type === 'blocked_contains') {
                if ($compact !== '' && stripos($compact, mh_registration_norm_compact($pattern)) !== false) {
                    return ['action' => $action ?: 'reject', 'reason' => 'policy_blocked_contains', 'pattern' => $pattern];
                }
            } elseif ($type === 'blocked_regex') {
                $re = $pattern;
                $ok = @preg_match($re, $rawValue);
                if ($ok === 1) {
                    return ['action' => $action ?: 'reject', 'reason' => 'policy_blocked_regex', 'pattern' => $pattern];
                }
            }
        }

        return null;
    }
}

if (!function_exists('mh_registration_rate_limit_check')) {
    function mh_registration_rate_limit_check(PDO $pdoBio, string $bucket, string $keyValue, int $limit, int $windowSec, int $blockSec): array
    {
        mh_registration_ensure_policy_schema($pdoBio);
        $bucket = trim($bucket);
        $keyValue = trim($keyValue);
        if ($bucket === '' || $keyValue === '') return ['ok' => true];

        $now = time();
        $hash = hash('sha256', $bucket . '|' . $keyValue);

        try {
            $stmt = $pdoBio->prepare("SELECT id, window_start, attempts, blocked_until FROM mh_registration_rate_limits WHERE key_hash = ? LIMIT 1");
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $row = null;
        }

        $windowStart = $now;
        $attempts = 0;
        $blockedUntil = null;
        $id = 0;
        if (is_array($row)) {
            $id = (int)($row['id'] ?? 0);
            $windowStart = (int)($row['window_start'] ?? $now);
            $attempts = (int)($row['attempts'] ?? 0);
            $blockedUntil = isset($row['blocked_until']) ? (int)$row['blocked_until'] : null;
        }

        if (is_int($blockedUntil) && $blockedUntil > $now) {
            return ['ok' => false, 'blocked_until' => $blockedUntil];
        }

        if (($now - $windowStart) > $windowSec) {
            $windowStart = $now;
            $attempts = 0;
        }
        $attempts++;
        if ($attempts > $limit) {
            $blockedUntil = $now + $blockSec;
        }

        try {
            if ($id > 0) {
                $upd = $pdoBio->prepare("UPDATE mh_registration_rate_limits SET attempts = ?, window_start = ?, last_seen = ?, blocked_until = ? WHERE id = ?");
                $upd->execute([$attempts, $windowStart, $now, $blockedUntil, $id]);
            } else {
                $ins = $pdoBio->prepare("INSERT INTO mh_registration_rate_limits (key_hash, bucket, key_value, window_start, attempts, last_seen, blocked_until) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$hash, $bucket, $keyValue, $windowStart, $attempts, $now, $blockedUntil]);
            }
        } catch (Throwable) {}

        if (is_int($blockedUntil) && $blockedUntil > $now) {
            return ['ok' => false, 'blocked_until' => $blockedUntil];
        }
        return ['ok' => true];
    }
}

if (!function_exists('mh_registration_review_enqueue')) {
    function mh_registration_review_enqueue(PDO $pdoBio, ?string $username, ?string $ipAddress, ?string $deviceFingerprint, string $scope, string $reason, ?string $rawValue): void
    {
        try {
            mh_registration_ensure_policy_schema($pdoBio);
            $stmt = $pdoBio->prepare("INSERT INTO mh_registration_review_queue (username, ip_address, device_fingerprint, scope, reason, raw_value) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                is_string($username) && trim($username) !== '' ? trim($username) : null,
                is_string($ipAddress) && trim($ipAddress) !== '' ? trim($ipAddress) : null,
                is_string($deviceFingerprint) && trim($deviceFingerprint) !== '' ? trim($deviceFingerprint) : null,
                trim($scope) !== '' ? trim($scope) : 'unknown',
                trim($reason) !== '' ? trim($reason) : 'unknown',
                is_string($rawValue) && trim($rawValue) !== '' ? trim($rawValue) : null,
            ]);
        } catch (Throwable) {}
    }
}
