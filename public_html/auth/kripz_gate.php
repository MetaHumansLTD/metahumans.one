<?php
declare(strict_types=1);

if (!defined('CUE_CORE_LOADED')) {
    require_once dirname(__DIR__) . '/.cue/cue.php';
}

$authClasses = dirname(__DIR__) . '/auth/auth_classes.php';
if (is_file($authClasses)) {
    require_once $authClasses;
}

$authFunctions = dirname(__DIR__) . '/auth/auth_functions.php';
if (is_file($authFunctions)) {
    require_once $authFunctions;
}

function mh_kripz_start_session(): void {
    if (function_exists('security_startSecureSession')) {
        security_startSecureSession();
        return;
    }
    if (function_exists('startSecureSession')) {
        startSecureSession();
        return;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function mh_kripz_is_role(): bool {
    $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
    return $role !== '' && strpos($role, 'kripzmaster') !== false;
}

function mh_kripz_refresh_role(string $username): void
{
    $username = trim($username);
    if ($username === '') return;

    $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
    if ($role !== '' && strpos($role, 'kripzmaster') !== false) return;

    if (function_exists('mh_auth_load_user_context')) {
        try {
            mh_auth_load_user_context($username);
        } catch (Throwable $e) {
        }
    }

    $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
    if ($role !== '' && strpos($role, 'kripzmaster') !== false) return;

    try {
        cue_autoload('database');
        $pdoBio = database_getConnectionById('biometrics');
        if (!$pdoBio instanceof PDO) {
            $raw = is_array($pdoBio) ? ($pdoBio['pdo'] ?? $pdoBio['connection'] ?? $pdoBio['dbh'] ?? null) : (is_object($pdoBio) ? ($pdoBio->pdo ?? $pdoBio->connection ?? $pdoBio->dbh ?? null) : null);
            if ($raw instanceof PDO) {
                $pdoBio = $raw;
            }
        }
        if (!$pdoBio instanceof PDO) {
            return;
        }
        $rawRole = '';
        try {
            $stmt = $pdoBio->prepare("SELECT role AS r FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $rawRole = isset($row['r']) ? strtolower((string)$row['r']) : '';
        } catch (Throwable $e) {
            $rawRole = '';
        }
        if ($rawRole === '') {
            try {
                $stmt = $pdoBio->prepare("SELECT roles AS r FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $rawRole = isset($row['r']) ? strtolower((string)$row['r']) : '';
            } catch (Throwable $e) {
                $rawRole = '';
            }
        }
        if ($rawRole !== '' && strpos($rawRole, 'kripzmaster') !== false) {
            $_SESSION['mh_auth_role'] = 'KripzMasters';
        }
    } catch (Throwable $e) {
    }
}

function mh_kripz_require(string $scope, bool $ajax = false): void {
    mh_kripz_start_session();

    $username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($username !== '') {
        mh_kripz_refresh_role($username);
    }

    if (!mh_kripz_is_role()) {
        if ($ajax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'forbidden'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(403);
        echo 'Access denied';
        exit;
    }

    $verifiedKey = 'mh_gate_verified_until';
    $csrfKey = 'mh_gate_csrf';
    $errorKey = 'mh_gate_error';
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($requestUri === '' || $requestUri[0] !== '/') {
        $requestUri = '/';
    }
    $verifiedUntil = isset($_SESSION[$verifiedKey]) ? (int)$_SESSION[$verifiedKey] : 0;
    $isVerified = $verifiedUntil > time();

    $csrf = isset($_SESSION[$csrfKey]) ? (string)$_SESSION[$csrfKey] : '';
    if ($csrf === '') {
        $csrf = bin2hex(random_bytes(16));
        $_SESSION[$csrfKey] = $csrf;
    }

    $errorMsg = isset($_SESSION[$errorKey]) ? (string)$_SESSION[$errorKey] : '';
    unset($_SESSION[$errorKey]);
    if (!$isVerified && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['username']) || isset($_POST['pin']) || isset($_POST['csrf']))) {
        $postUser = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
        $postPin = isset($_POST['pin']) ? trim((string)$_POST['pin']) : '';
        $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

        if (!hash_equals($csrf, $postCsrf)) {
            $errorMsg = 'Invalid request';
        } elseif ($postUser === '' || $postPin === '') {
            $errorMsg = '';
        } elseif ($username !== '' && strcasecmp($postUser, $username) !== 0) {
            $errorMsg = 'Username mismatch';
        } elseif (!preg_match('/^[0-9]{5,}$/', $postPin)) {
            $errorMsg = 'Invalid PIN format';
        } else {
            try {
                if (class_exists('MetaPinBackup')) {
                    $pinBackup = new MetaPinBackup();
                    $pinBackup->verifyPin($postUser, $postPin);
                } else {
                    throw new RuntimeException('pin_class_missing');
                }
                cue_autoload('database');
                $pdoBio = database_getConnectionById('biometrics');
                if (!$pdoBio instanceof PDO) {
                    $raw = is_array($pdoBio) ? ($pdoBio['pdo'] ?? $pdoBio['connection'] ?? $pdoBio['dbh'] ?? null) : (is_object($pdoBio) ? ($pdoBio->pdo ?? $pdoBio->connection ?? $pdoBio->dbh ?? null) : null);
                    if ($raw instanceof PDO) {
                        $pdoBio = $raw;
                    }
                }
                if (!$pdoBio instanceof PDO) {
                    throw new RuntimeException('biometrics_unavailable');
                }
                $stmt = $pdoBio->prepare("SELECT role FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$postUser]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $rawRole = isset($row['role']) ? strtolower((string)$row['role']) : '';
                if ($rawRole === '') {
                    try {
                        $stmt = $pdoBio->prepare("SELECT roles FROM users WHERE username = ? LIMIT 1");
                        $stmt->execute([$postUser]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $rawRole = isset($row['roles']) ? strtolower((string)$row['roles']) : '';
                    } catch (Throwable $e) {
                        $rawRole = '';
                    }
                }
                if (strpos($rawRole, 'kripzmaster') === false) {
                    $errorMsg = 'Access denied';
                } else {
                    $_SESSION['mh_auth_role'] = 'KripzMasters';
                    $_SESSION[$verifiedKey] = time() + 43200;
                    unset($_SESSION[$errorKey]);
                    $isVerified = true;
                }
            } catch (Throwable $e) {
                $msg = trim((string)$e->getMessage());
                if ($msg === 'pin_class_missing') {
                    $errorMsg = 'PIN system unavailable';
                } elseif ($msg === 'biometrics_unavailable') {
                    $errorMsg = 'Biometrics unavailable';
                } elseif ($msg !== '') {
                    $errorMsg = $msg;
                } else {
                    $errorMsg = 'Access denied';
                }
            }
        }

        if ($isVerified) {
            header('Location: ' . $requestUri, true, 303);
            exit;
        }

        $_SESSION[$errorKey] = $errorMsg;
        header('Location: ' . $requestUri, true, 303);
        exit;
    }

    if ($isVerified) {
        return;
    }

    if ($ajax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => 'reauth_required'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
    if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
    if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);

    while (ob_get_level()) {
        ob_end_clean();
    }

    http_response_code(401);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Verify Access</title>
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
        <style>
            .mh-auth-wrap { max-width: 560px; margin: 0 auto; }
            .mh-auth-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 18px; }
            .mh-auth-title { margin: 0 0 8px 0; }
            .mh-auth-muted { opacity: 0.8; font-size: 12px; margin-bottom: 14px; }
            .mh-auth-label { display:block; margin: 12px 0 6px; }
            .mh-auth-input { width: 100%; max-width: 100%; box-sizing: border-box; display: block; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color: #fff; }
            .mh-auth-btn { margin-top: 14px; width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: #0aa0b6; color:#fff; cursor:pointer; font-weight:700; }
            .mh-auth-error { margin-top: 12px; color: #ffb3b3; }
        </style>
    </head>
    <body>
        <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
        <main class="main-content">
            <section style="padding: 30px 0;">
                <div class="container mh-auth-wrap">
                    <h1 class="mh-auth-title">Verify Access</h1>
                    <div class="mh-auth-muted">KripzMasters only. Please confirm username + PIN (unlocks for 12 hours).</div>
                    <div class="mh-auth-card">
                        <form method="post" action="<?php echo htmlspecialchars($requestUri, ENT_QUOTES); ?>" autocomplete="off">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
                            <label class="mh-auth-label">Username</label>
                            <input class="mh-auth-input" type="text" name="username" value="<?php echo htmlspecialchars($username, ENT_QUOTES); ?>" autocomplete="username" required />
                            <label class="mh-auth-label">PIN</label>
                            <input class="mh-auth-input" type="password" name="pin" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="current-password" required />
                            <button class="mh-auth-btn" type="submit">Unlock</button>
                            <?php if ($errorMsg !== ''): ?>
                                <div class="mh-auth-error"><?php echo htmlspecialchars($errorMsg, ENT_QUOTES); ?></div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </section>
        </main>
        <?php
        if (function_exists('renderGlobalFooter')) {
            renderGlobalFooter(['ftr_position' => 'bottom', 'ftr_auto_offset' => false]);
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (strpos($uri, '/pdf-tools') !== 0) {
            if (function_exists('renderGlobalWidgets')) {
                renderGlobalWidgets();
            } elseif (function_exists('renderGlobalStatusBar')) {
                renderGlobalStatusBar();
            }
        }
        if (function_exists('includeGlobalUIScripts')) {
            includeGlobalUIScripts();
        }
        ?>
    </body>
    </html>
    <?php
    exit;
}
