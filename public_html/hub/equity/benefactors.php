<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/../benefactors/lib.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$ajax = isset($_GET['ajax']) ? trim((string)$_GET['ajax']) : '';
$isAjaxLookup = ($ajax === 'benefactor_lookup');
$isAjaxGuardianLookup = ($ajax === 'guardian_lookup');
$isAjaxRuleSave = ($ajax === 'benefactor_rule_save');
$isAjaxEstatePlanAutosave = ($ajax === 'estate_plan_autosave');
$isAjaxEstatePasskeyStart = ($ajax === 'estate_passkey_start');
$isAjaxEstatePasskeyFinish = ($ajax === 'estate_passkey_finish');
if (!isset($_SESSION['mh_auth_user'])) {
    if ($isAjaxLookup || $isAjaxGuardianLookup || $isAjaxRuleSave || $isAjaxEstatePlanAutosave || $isAjaxEstatePasskeyStart || $isAjaxEstatePasskeyFinish) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode('/hub/equity/benefactors.php'));
    exit;
}
$username = (string)$_SESSION['mh_auth_user'];

$templatesLoaded = false;
if (!$isAjaxLookup && !$isAjaxGuardianLookup && !$isAjaxRuleSave && !$isAjaxEstatePlanAutosave && !$isAjaxEstatePasskeyStart && !$isAjaxEstatePasskeyFinish) {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/../../templates/global-ui/functions.php';
    $templatesLoaded = true;
}

$message = '';
if (isset($_SESSION['mh_benefactors_flash']) && is_string($_SESSION['mh_benefactors_flash'])) {
    $message = (string)$_SESSION['mh_benefactors_flash'];
    unset($_SESSION['mh_benefactors_flash']);
}

try {
    $pdo = mh_benefactors_pdo();
    mh_benefactors_sweep_expired($pdo);
    mh_estate_apply_due_tranches($pdo, $username);
} catch (Throwable $e) {
    try {
        if (function_exists('cue_autoload')) {
            $err = cue_autoload('error');
            if ($err && method_exists($err, 'logError')) {
                $err->logError('Benefactors page failed to initialize', [
                    'error' => $e->getMessage(),
                    'script' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
                    'path' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                ]);
            }
        }
    } catch (Throwable $e2) {}
    http_response_code(500);
    echo 'Service unavailable';
    exit;
}

$csrf = isset($_SESSION['mh_benefactors_csrf']) && is_string($_SESSION['mh_benefactors_csrf']) ? (string)$_SESSION['mh_benefactors_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_benefactors_csrf'] = $csrf;
}

if ($isAjaxLookup) {
    $u = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
    $rf = isset($_GET['real_first_name']) ? trim((string)$_GET['real_first_name']) : '';
    $rl = isset($_GET['real_last_name']) ? trim((string)$_GET['real_last_name']) : '';
    $row = $u !== '' ? mh_benefactors_get_user_row($pdo, $u) : null;
    $dbFirst = is_array($row) ? trim((string)($row['real_first_name'] ?? '')) : '';
    $dbLast = is_array($row) ? trim((string)($row['real_last_name'] ?? '')) : '';
    $dbTokens = is_array($row) ? (int)($row['tokens'] ?? 0) : 0;
    $min = mh_benefactors_min_tokens_required();
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode([
        'ok' => true,
        'exists' => is_array($row),
        'has_real_name' => ($dbFirst !== '' && $dbLast !== ''),
        'name_match' => ($dbFirst !== '' && $dbLast !== '' && $rf !== '' && $rl !== '' && strcasecmp($dbFirst, $rf) === 0 && strcasecmp($dbLast, $rl) === 0),
        'tokens_ok' => ($dbTokens >= $min),
        'token_balance' => $dbTokens,
        'min_tokens' => $min,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjaxGuardianLookup) {
    $u = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
    $rf = isset($_GET['real_first_name']) ? trim((string)$_GET['real_first_name']) : '';
    $rl = isset($_GET['real_last_name']) ? trim((string)$_GET['real_last_name']) : '';
    $row = $u !== '' ? mh_benefactors_get_user_row($pdo, $u) : null;
    $dbFirst = is_array($row) ? trim((string)($row['real_first_name'] ?? '')) : '';
    $dbLast = is_array($row) ? trim((string)($row['real_last_name'] ?? '')) : '';
    $dbTokens = is_array($row) ? (int)($row['tokens'] ?? 0) : 0;
    $min = mh_benefactors_min_tokens_required();
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode([
        'ok' => true,
        'exists' => is_array($row),
        'has_real_name' => ($dbFirst !== '' && $dbLast !== ''),
        'name_match' => ($dbFirst !== '' && $dbLast !== '' && $rf !== '' && $rl !== '' && strcasecmp($dbFirst, $rf) === 0 && strcasecmp($dbLast, $rl) === 0),
        'tokens_ok' => ($dbTokens >= $min),
        'token_balance' => $dbTokens,
        'min_tokens' => $min,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjaxRuleSave) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $bUser = trim((string)($_POST['benefactor_username'] ?? ''));
    $asset = trim((string)($_POST['asset_type'] ?? ''));
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'equal')));
    $val = isset($_POST['value_num']) ? (float)$_POST['value_num'] : null;
    if ($bUser === '' || $asset === '') {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'missing_fields'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!in_array($mode, ['equal', 'percent', 'all'], true)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'invalid_mode'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($mode !== 'percent') $val = null;
    if ($mode === 'percent' && $val !== null) {
        $val = max(0.0, min(100.0, (float)$val));
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO benefactor_asset_rules (owner_username, benefactor_username, asset_type, mode, value_num) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE mode = VALUES(mode), value_num = VALUES(value_num)");
        $stmt->execute([$username, $bUser, $asset, $mode, $val]);
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'save_failed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($isAjaxEstatePlanAutosave) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $inactivityDays = (int)($_POST['inactivity_days'] ?? 90);
    $challengeDays = (int)($_POST['challenge_days'] ?? 7);
    $guardianQuorum = (int)($_POST['guardian_quorum'] ?? 2);
    $bondAmount = (int)($_POST['bond_amount_mtk'] ?? 1000);
    $trancheCount = (int)($_POST['tranche_count'] ?? 6);
    $trancheIntervalDays = (int)($_POST['tranche_interval_days'] ?? 30);
    $ok = mh_estate_plan_update($pdo, $username, $inactivityDays, $challengeDays, $guardianQuorum, $bondAmount, $trancheCount, $trancheIntervalDays);
    http_response_code($ok ? 200 : 500);
    header('Content-Type: application/json; charset=UTF-8');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($isAjaxEstatePasskeyStart) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $purpose = strtolower(trim((string)($_POST['purpose'] ?? 'checkin')));
    if (!in_array($purpose, ['checkin', 'halt'], true)) $purpose = 'checkin';
    $claimId = (int)($_POST['claim_id'] ?? 0);
    try {
        require_once __DIR__ . '/../../auth/auth_classes.php';
        $auth = new MetaPasskeyAuth();
        $challenge = $auth->generateAuthenticationChallenge($username);
        $_SESSION['mh_estate_passkey_purpose'] = $purpose;
        $_SESSION['mh_estate_passkey_claim_id'] = $claimId;
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo json_encode([
            'ok' => true,
            'challengeId' => $challenge['challengeId'],
            'publicKey' => $challenge['options'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'passkey_start_failed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($isAjaxEstatePasskeyFinish) {
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $challengeId = trim((string)($_POST['challengeId'] ?? ''));
    $credential = $_POST['credential'] ?? null;
    if (is_string($credential) && $credential !== '') {
        $d = json_decode($credential, true);
        if (is_array($d)) $credential = $d;
    }
    if ($challengeId === '' || !is_array($credential)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'invalid_payload'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $purpose = isset($_SESSION['mh_estate_passkey_purpose']) ? strtolower(trim((string)$_SESSION['mh_estate_passkey_purpose'])) : 'checkin';
    $claimId = isset($_SESSION['mh_estate_passkey_claim_id']) ? (int)$_SESSION['mh_estate_passkey_claim_id'] : 0;
    unset($_SESSION['mh_estate_passkey_purpose'], $_SESSION['mh_estate_passkey_claim_id']);

    try {
        require_once __DIR__ . '/../../auth/auth_classes.php';
        $auth = new MetaPasskeyAuth();
        $assertion = [
            'id' => $credential['id'] ?? '',
            'response' => [
                'authenticatorData' => $credential['response']['authenticatorData'] ?? '',
                'clientDataJSON' => $credential['response']['clientDataJSON'] ?? '',
                'signature' => $credential['response']['signature'] ?? '',
            ],
        ];
        $rawUserId = (string)$auth->verifyAuthentication($challengeId, $assertion);
        $okUser = $rawUserId !== '' && strcasecmp($rawUserId, $username) === 0;
        if (!$okUser && function_exists('mh_auth_resolve_username_from_login_id')) {
            $resolved = mh_auth_resolve_username_from_login_id($rawUserId);
            if ($resolved !== '' && strcasecmp($resolved, $username) === 0) $okUser = true;
        }
        if (!$okUser) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode(['ok' => false, 'error' => 'passkey_user_mismatch'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($purpose === 'halt') {
            $r = mh_estate_claim_halt($pdo, $claimId, $username);
            http_response_code(($r['ok'] ?? null) === true ? 200 : 400);
            header('Content-Type: application/json; charset=UTF-8');
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo json_encode($r, JSON_UNESCAPED_SLASHES);
            exit;
        }

        $ok = mh_estate_checkin($pdo, $username);
        http_response_code($ok ? 200 : 500);
        header('Content-Type: application/json; charset=UTF-8');
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo json_encode(['ok' => $ok], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        while (ob_get_level() > 0) { @ob_end_clean(); }
        echo json_encode(['ok' => false, 'error' => 'passkey_finish_failed'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$benefactorAssetsOwner = mh_benefactors_asset_snapshot($username);
$benefactorsOwned = [];
$benefactorRulesOwned = [];
$myAppointments = [];
$pendingClaimsForMe = [];
$myClaims = [];

try {
    $stmt = $pdo->prepare("SELECT * FROM benefactors WHERE owner_username = ? ORDER BY created_at DESC");
    $stmt->execute([$username]);
    $benefactorsOwned = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT * FROM benefactor_asset_rules WHERE owner_username = ?");
    $stmt->execute([$username]);
    $benefactorRulesOwned = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT b.owner_username, b.status, b.created_at FROM benefactors b WHERE b.benefactor_username = ? AND b.status IN ('pending','active') ORDER BY b.created_at DESC");
    $stmt->execute([$username]);
    $myAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("SELECT r.claim_id, r.kyc_room_id, c.owner_username, c.initiated_by, c.created_at
        FROM benefactor_claim_responses r
        JOIN benefactor_claims c ON c.id = r.claim_id
        WHERE r.benefactor_username = ? AND r.status = 'pending' AND c.status = 'open'
        ORDER BY c.created_at DESC");
    $stmt->execute([$username]);
    $pendingClaimsForMe = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $myClaims = mh_benefactors_my_claims($pdo, $username);
} catch (Throwable $e) {
    $benefactorsOwned = [];
    $benefactorRulesOwned = [];
    $myAppointments = [];
    $pendingClaimsForMe = [];
    $myClaims = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && is_string($_POST['action'])) {
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $message = 'Invalid request';
    } else {
        $action = (string)$_POST['action'];

        if ($action === 'benefactor_add') {
            $bFirst = trim((string)($_POST['benefactor_real_first_name'] ?? ''));
            $bLast = trim((string)($_POST['benefactor_real_last_name'] ?? ''));
            $bUser = trim((string)($_POST['benefactor_username'] ?? ''));
            if ($bFirst === '' || $bLast === '' || $bUser === '') {
                $message = 'Benefactor real name, real surname, and username are required.';
            } else {
                $row = mh_benefactors_get_user_row($pdo, $bUser);
                $dbFirst = is_array($row) ? trim((string)($row['real_first_name'] ?? '')) : '';
                $dbLast = is_array($row) ? trim((string)($row['real_last_name'] ?? '')) : '';
                $dbTokens = is_array($row) ? (int)($row['tokens'] ?? 0) : 0;
                $min = mh_benefactors_min_tokens_required();
                if (!is_array($row)) {
                    $message = 'Benefactor user not found.';
                } elseif ($dbFirst === '' || $dbLast === '') {
                    $message = 'Benefactor must have Real Name and Real Surname set in account settings.';
                } elseif (strcasecmp($dbFirst, $bFirst) !== 0 || strcasecmp($dbLast, $bLast) !== 0) {
                    $message = 'Benefactor real name does not match.';
                } elseif ($dbTokens < $min) {
                    $message = 'Benefactor does not meet MTK requirement.';
                } else {
                    try {
                        $pdo->beginTransaction();
                        $bName = trim($dbFirst . ' ' . $dbLast);
                        $stmt = $pdo->prepare("INSERT INTO benefactors (owner_username, benefactor_username, benefactor_name, status) VALUES (?, ?, ?, 'pending')
                            ON DUPLICATE KEY UPDATE benefactor_name = VALUES(benefactor_name), status = 'pending'");
                        $stmt->execute([$username, $bUser, $bName]);

                        foreach ($benefactorAssetsOwner as $a) {
                            $t = isset($a['type']) ? trim((string)$a['type']) : '';
                            if ($t === '') continue;
                            $stmt = $pdo->prepare("INSERT INTO benefactor_asset_rules (owner_username, benefactor_username, asset_type, mode, value_num) VALUES (?, ?, ?, 'equal', NULL)
                                ON DUPLICATE KEY UPDATE mode = mode");
                            $stmt->execute([$username, $bUser, $t]);
                        }
                        $pdo->commit();
                        $_SESSION['mh_benefactors_flash'] = 'Benefactor invitation sent.';
                        header('Location: /hub/equity/benefactors.php?benefactor=' . rawurlencode($bUser));
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $message = 'Benefactor add failed.';
                    }
                }
            }
        } elseif ($action === 'benefactor_delete') {
            $bUser = trim((string)($_POST['benefactor_username'] ?? ''));
            if ($bUser === '') {
                $message = 'Missing benefactor.';
            } else {
                try {
                    $pdo->beginTransaction();
                    try {
                        $stmt = $pdo->prepare("SELECT id FROM benefactor_claims WHERE owner_username = ? AND initiated_by = ? AND status = 'open'");
                        $stmt->execute([$username, $bUser]);
                        $claimIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                        $claimIds = array_values(array_filter(array_map('intval', $claimIds), fn($x) => $x > 0));
                        if (!empty($claimIds)) {
                            $in = implode(',', array_fill(0, count($claimIds), '?'));
                            $pdo->prepare("UPDATE benefactor_claims SET status = 'void' WHERE id IN ($in)")->execute($claimIds);
                            $pdo->prepare("UPDATE benefactor_claim_responses SET status = 'revoked', decided_at = COALESCE(decided_at, NOW()) WHERE claim_id IN ($in)")->execute($claimIds);
                        }
                    } catch (Throwable $e) {}
                    try {
                        $pdo->prepare("UPDATE benefactor_claim_responses r
                            JOIN benefactor_claims c ON c.id = r.claim_id
                            SET r.status = 'revoked', r.decided_at = COALESCE(r.decided_at, NOW())
                            WHERE c.owner_username = ? AND c.status = 'open' AND r.benefactor_username = ?")->execute([$username, $bUser]);
                    } catch (Throwable $e) {}
                    $pdo->prepare("DELETE FROM benefactor_asset_rules WHERE owner_username = ? AND benefactor_username = ?")->execute([$username, $bUser]);
                    $pdo->prepare("DELETE FROM benefactors WHERE owner_username = ? AND benefactor_username = ?")->execute([$username, $bUser]);
                    $pdo->commit();
                    $_SESSION['mh_benefactors_flash'] = 'Benefactor removed.';
                    header('Location: /hub/equity/benefactors.php');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = 'Benefactor delete failed.';
                }
            }
        } elseif ($action === 'benefactor_rule_save') {
            $bUser = trim((string)($_POST['benefactor_username'] ?? ''));
            $asset = trim((string)($_POST['asset_type'] ?? ''));
            $mode = strtolower(trim((string)($_POST['mode'] ?? 'equal')));
            $val = isset($_POST['value_num']) ? (float)$_POST['value_num'] : null;
            if ($bUser === '' || $asset === '') {
                $message = 'Missing rule fields.';
            } elseif (!in_array($mode, ['equal', 'percent', 'all'], true)) {
                $message = 'Invalid rule.';
            } else {
                if ($mode !== 'percent') $val = null;
                if ($mode === 'percent' && $val !== null) {
                    $val = max(0.0, min(100.0, (float)$val));
                }
                try {
                    $stmt = $pdo->prepare("INSERT INTO benefactor_asset_rules (owner_username, benefactor_username, asset_type, mode, value_num) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE mode = VALUES(mode), value_num = VALUES(value_num)");
                    $stmt->execute([$username, $bUser, $asset, $mode, $val]);
                    $_SESSION['mh_benefactors_flash'] = 'Saved.';
                    header('Location: /hub/equity/benefactors.php');
                    exit;
                } catch (Throwable $e) {
                    $message = 'Save failed.';
                }
            }
        } elseif ($action === 'benefactor_appointment_decide') {
            $ownerUser = trim((string)($_POST['owner_username'] ?? ''));
            $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
            if ($ownerUser === '' || !in_array($decision, ['accept', 'deny'], true)) {
                $message = 'Invalid action.';
            } else {
                try {
                    $newStatus = $decision === 'accept' ? 'active' : 'declined';
                    $stmt = $pdo->prepare("UPDATE benefactors SET status = ? WHERE owner_username = ? AND benefactor_username = ? AND status = 'pending'");
                    $stmt->execute([$newStatus, $ownerUser, $username]);
                    $_SESSION['mh_benefactors_flash'] = $decision === 'accept' ? 'Benefactor appointment accepted.' : 'Benefactor appointment declined.';
                    header('Location: /hub/equity/benefactors.php');
                    exit;
                } catch (Throwable $e) {
                    $message = 'Update failed.';
                }
            }
        } elseif ($action === 'benefactor_claim_initiate') {
            $ownerUser = trim((string)($_POST['owner_username'] ?? ''));
            if ($ownerUser === '') {
                $message = 'Owner username is required.';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM benefactors WHERE owner_username = ? AND benefactor_username = ? AND status = 'active' LIMIT 1");
                $stmt->execute([$ownerUser, $username]);
                $ok = (int)$stmt->fetchColumn() > 0;
                if (!$ok) {
                    $message = 'You are not an active benefactor for this owner.';
                } else {
                    try {
                        $snapshot = mh_benefactors_asset_snapshot($ownerUser);
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("INSERT INTO benefactor_claims (owner_username, initiated_by, status, snapshot_json) VALUES (?, ?, 'open', ?)");
                        $stmt->execute([$ownerUser, $username, json_encode($snapshot, JSON_UNESCAPED_SLASHES)]);
                        $claimId = (int)$pdo->lastInsertId();
                        $pdo->prepare("UPDATE benefactor_claims SET kyc_room_id = ? WHERE id = ?")->execute(['benefactor_claim_' . $claimId, $claimId]);

                        $stmt = $pdo->prepare("SELECT benefactor_username FROM benefactors WHERE owner_username = ? AND status = 'active'");
                        $stmt->execute([$ownerUser]);
                        $list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $ins = $pdo->prepare("INSERT IGNORE INTO benefactor_claim_responses (claim_id, benefactor_username, kyc_room_id, status) VALUES (?, ?, ?, 'pending')");
                        foreach ($list as $r) {
                            $bu = isset($r['benefactor_username']) ? trim((string)$r['benefactor_username']) : '';
                            if ($bu === '') continue;
                            $safeBu = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $bu);
                            $safeBu = trim((string)$safeBu, '_');
                            $roomId = 'benefactor_claim_' . $claimId . '_' . $safeBu;
                            if (strlen($roomId) > 64) {
                                $roomId = substr($roomId, 0, 64);
                            }
                            $ins->execute([$claimId, $bu, $roomId]);
                        }
                        $pdo->commit();
                        $_SESSION['mh_benefactors_flash'] = 'Claim created. Benefactors have been notified.';
                        header('Location: /hub/equity/benefactors.php');
                        exit;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $message = 'Claim failed.';
                    }
                }
            }
        } elseif ($action === 'benefactor_claim_decide') {
            $claimId = (int)($_POST['claim_id'] ?? 0);
            $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
            if ($claimId < 1 || !in_array($decision, ['accept', 'deny'], true)) {
                $message = 'Invalid action.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT status FROM benefactor_claim_responses WHERE claim_id = ? AND benefactor_username = ? LIMIT 1 FOR UPDATE");
                    $stmt->execute([$claimId, $username]);
                    $cur = (string)$stmt->fetchColumn();
                    if ($cur === '' || $cur !== 'pending') {
                        $pdo->rollBack();
                        $_SESSION['mh_benefactors_flash'] = 'Nothing to update.';
                        header('Location: /hub/equity/benefactors.php');
                        exit;
                    }
                    $newStatus = $decision === 'accept' ? 'accepted' : 'denied';
                    $stmt = $pdo->prepare("UPDATE benefactor_claim_responses SET status = ?, decided_at = NOW() WHERE claim_id = ? AND benefactor_username = ?");
                    $stmt->execute([$newStatus, $claimId, $username]);
                    $pdo->commit();
                    $_SESSION['mh_benefactors_flash'] = $newStatus === 'accepted' ? 'Accepted.' : 'Denied.';
                    header('Location: /hub/equity/benefactors.php');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $message = 'Update failed.';
                }
            }
        } elseif ($action === 'benefactor_claim_execute') {
            $claimId = (int)($_POST['claim_id'] ?? 0);
            if ($claimId < 1) {
                $message = 'Invalid claim.';
            } else {
                $r = mh_benefactors_execute_claim($pdo, $claimId, $username);
                if (($r['ok'] ?? null) === true) {
                    $_SESSION['mh_benefactors_flash'] = 'Claim executed.';
                    header('Location: /hub/equity/benefactors.php');
                    exit;
                }
                $errCode = isset($r['error']) ? (string)$r['error'] : 'execute_failed';
                $message = 'Execute failed: ' . $errCode;
            }
        } elseif ($action === 'estate_plan_save') {
            $inactivityDays = (int)($_POST['inactivity_days'] ?? 90);
            $challengeDays = (int)($_POST['challenge_days'] ?? 7);
            $guardianQuorum = (int)($_POST['guardian_quorum'] ?? 2);
            $bondAmount = (int)($_POST['bond_amount_mtk'] ?? 1000);
            $trancheCount = (int)($_POST['tranche_count'] ?? 6);
            $trancheIntervalDays = (int)($_POST['tranche_interval_days'] ?? 30);
            $ok = mh_estate_plan_update($pdo, $username, $inactivityDays, $challengeDays, $guardianQuorum, $bondAmount, $trancheCount, $trancheIntervalDays);
            $_SESSION['mh_benefactors_flash'] = $ok ? 'Estate plan updated.' : 'Estate plan update failed.';
            header('Location: /hub/equity/benefactors.php');
            exit;
        } elseif ($action === 'estate_guardian_add') {
            $gFirst = trim((string)($_POST['guardian_real_first_name'] ?? ''));
            $gLast = trim((string)($_POST['guardian_real_last_name'] ?? ''));
            $gUser = trim((string)($_POST['guardian_username'] ?? ''));
            if ($gFirst === '' || $gLast === '' || $gUser === '') {
                $message = 'Guardian real name, real surname, and username are required.';
            } else {
                $row = mh_benefactors_get_user_row($pdo, $gUser);
                $dbFirst = is_array($row) ? trim((string)($row['real_first_name'] ?? '')) : '';
                $dbLast = is_array($row) ? trim((string)($row['real_last_name'] ?? '')) : '';
                $dbTokens = is_array($row) ? (int)($row['tokens'] ?? 0) : 0;
                $min = mh_benefactors_min_tokens_required();
                if (!is_array($row)) {
                    $message = 'Guardian user not found.';
                } elseif ($dbFirst === '' || $dbLast === '') {
                    $message = 'Guardian must have Real Name and Real Surname set in account settings.';
                } elseif (strcasecmp($dbFirst, $gFirst) !== 0 || strcasecmp($dbLast, $gLast) !== 0) {
                    $message = 'Guardian real name does not match.';
                } elseif ($dbTokens < $min) {
                    $message = 'Guardian does not meet MTK requirement.';
                } else {
                    $ok = mh_estate_guardian_add($pdo, $username, (string)$row['username']);
                    $_SESSION['mh_benefactors_flash'] = $ok ? 'Guardian added.' : 'Guardian add failed.';
                    header('Location: /hub/equity/benefactors.php');
                    exit;
                }
            }
        } elseif ($action === 'estate_guardian_remove') {
            $g = trim((string)($_POST['guardian_username'] ?? ''));
            $ok = $g !== '' ? mh_estate_guardian_remove($pdo, $username, $g) : false;
            $_SESSION['mh_benefactors_flash'] = $ok ? 'Guardian removed.' : 'Guardian remove failed.';
            header('Location: /hub/equity/benefactors.php');
            exit;
        } elseif ($action === 'estate_guardian_stake_lock') {
            $ownerUser = trim((string)($_POST['owner_username'] ?? ''));
            $r = $ownerUser !== '' ? mh_estate_guardian_lock_stake($pdo, $ownerUser, $username) : ['ok' => false, 'error' => 'invalid_request'];
            if (($r['ok'] ?? null) === true) {
                $_SESSION['mh_benefactors_flash'] = 'Stake locked.';
                header('Location: /hub/equity/benefactors.php');
                exit;
            }
            $message = 'Stake lock failed: ' . (string)($r['error'] ?? 'failed');
        } elseif ($action === 'estate_claim_create') {
            $ownerUser = trim((string)($_POST['owner_username'] ?? ''));
            $r = $ownerUser !== '' ? mh_estate_claim_create($pdo, $ownerUser, $username) : ['ok' => false, 'error' => 'invalid_request'];
            if (($r['ok'] ?? null) === true) {
                $_SESSION['mh_benefactors_flash'] = 'Dead-man claim created.';
                header('Location: /hub/equity/benefactors.php');
                exit;
            }
            $message = 'Dead-man claim failed: ' . (string)($r['error'] ?? 'failed');
        } elseif ($action === 'estate_guardian_vote') {
            $claimId = (int)($_POST['claim_id'] ?? 0);
            $decision = strtolower(trim((string)($_POST['decision'] ?? '')));
            $r = mh_estate_claim_vote($pdo, $claimId, $username, $decision);
            if (($r['ok'] ?? null) === true) {
                $_SESSION['mh_benefactors_flash'] = 'Vote recorded.';
                header('Location: /hub/equity/benefactors.php');
                exit;
            }
            $message = 'Vote failed: ' . (string)($r['error'] ?? 'failed');
        }
    }
}

$rulesByUser = [];
foreach ($benefactorRulesOwned as $rr) {
    $bu = isset($rr['benefactor_username']) ? (string)$rr['benefactor_username'] : '';
    $at = isset($rr['asset_type']) ? (string)$rr['asset_type'] : '';
    if ($bu !== '' && $at !== '') {
        $rulesByUser[$bu][$at] = $rr;
    }
}

$pendingAppointments = [];
$activeAppointments = [];
foreach ($myAppointments as $o) {
    $st = strtolower(trim((string)($o['status'] ?? '')));
    if ($st === 'pending') $pendingAppointments[] = $o;
    if ($st === 'active') $activeAppointments[] = $o;
}

$pendingClaimsComputed = [];
foreach ($pendingClaimsForMe as $p) {
    $claimId = (int)($p['claim_id'] ?? 0);
    if ($claimId < 1) continue;
    $owner = (string)($p['owner_username'] ?? '');
    $kycRoom = (string)($p['kyc_room_id'] ?? '');
    $verified = false;
    $types = [];
    $preview = [];
    try {
        if ($kycRoom !== '') {
            $stmt = $pdo->prepare("SELECT status FROM user_kyc_sessions WHERE username = ? AND session_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$username, $kycRoom]);
            $verified = (strtolower(trim((string)$stmt->fetchColumn())) === 'verified');
        }
        $stmt = $pdo->prepare("SELECT snapshot_json FROM benefactor_claims WHERE id = ? LIMIT 1");
        $stmt->execute([$claimId]);
        $snapRaw = (string)$stmt->fetchColumn();
        $snap = $snapRaw !== '' ? json_decode($snapRaw, true) : null;
        if (is_array($snap)) {
            foreach ($snap as $a) {
                $t = isset($a['type']) ? trim((string)$a['type']) : '';
                if ($t !== '') $types[$t] = true;
            }
        }

        if ($verified && is_array($snap)) {
            $stmt = $pdo->prepare("SELECT benefactor_username FROM benefactors WHERE owner_username = ? AND status = 'active'");
            $stmt->execute([$owner]);
            $ownerBenefactors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stmt = $pdo->prepare("SELECT * FROM benefactor_asset_rules WHERE owner_username = ?");
            $stmt->execute([$owner]);
            $ownerRules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($snap as $a) {
                $t = isset($a['type']) ? trim((string)$a['type']) : '';
                $qtyRaw = isset($a['qty']) ? (float)$a['qty'] : 0.0;
                $qty = (int)floor(max(0.0, $qtyRaw));
                if ($t === '' || $qty <= 0) continue;
                $alloc = mh_benefactors_compute_allocations($ownerBenefactors, $ownerRules, $t);
                $pct = isset($alloc[$username]) ? (float)$alloc[$username] : 0.0;
                if ($pct <= 0) continue;
                $amt = (int)floor($qty * ($pct / 100.0));
                if ($amt <= 0) continue;
                $preview[$t] = $amt;
            }
        }
    } catch (Throwable $e) {}
    $pendingClaimsComputed[] = [
        'claim_id' => $claimId,
        'owner_username' => $owner,
        'initiated_by' => (string)($p['initiated_by'] ?? ''),
        'created_at' => (string)($p['created_at'] ?? ''),
        'kyc_room_id' => $kycRoom,
        'proof_verified' => $verified,
        'asset_types' => array_keys($types),
        'allocation_preview' => $preview,
    ];
}

$assetLabelMap = [
    'utility_token' => 'Utility Token (MTK)',
    'equity_coins' => 'Equity Coins',
    'equity_ordinary_coins' => 'Equity Ordinary (Coins)',
    'equity_preference_coins' => 'Equity Preference (Coins)',
];
$labelForAsset = function (string $t) use ($assetLabelMap): string {
    $t = trim($t);
    return $t !== '' && isset($assetLabelMap[$t]) ? (string)$assetLabelMap[$t] : $t;
};

$ownerAllocationSummary = [];
try {
    foreach ($benefactorAssetsOwner as $a) {
        $t = isset($a['type']) ? trim((string)$a['type']) : '';
        if ($t === '') continue;
        $qty = (int)floor((float)($a['qty'] ?? 0));
        $alloc = function_exists('mh_benefactors_compute_allocations') ? mh_benefactors_compute_allocations($benefactorsOwned, $benefactorRulesOwned, $t) : [];
        $assignedPct = 0.0;
        if (is_array($alloc)) {
            foreach ($alloc as $u => $pct) {
                $assignedPct += max(0.0, (float)$pct);
            }
        }
        $assignedPct = max(0.0, min(100.0, $assignedPct));
        $unassignedPct = max(0.0, 100.0 - $assignedPct);
        $assignedAmt = $qty > 0 ? (int)floor($qty * ($assignedPct / 100.0)) : 0;
        $unassignedAmt = max(0, $qty - $assignedAmt);
        $ownerAllocationSummary[] = [
            'type' => $t,
            'label' => $labelForAsset($t),
            'qty' => $qty,
            'assigned_pct' => $assignedPct,
            'unassigned_pct' => $unassignedPct,
            'assigned_qty' => $assignedAmt,
            'unassigned_qty' => $unassignedAmt,
        ];
    }
} catch (Throwable $e) {
    $ownerAllocationSummary = [];
}

$estatePlan = mh_estate_get_plan($pdo, $username);
$estateInactive = !empty($estatePlan) ? mh_estate_is_inactive($estatePlan) : false;
$estateGuardians = mh_estate_guardians_list($pdo, $username);
$guardianKycOk = mh_benefactors_user_kyc_is_verified($pdo, $username);
$selectedEstateGuardian = isset($_GET['estate_guardian']) ? trim((string)$_GET['estate_guardian']) : '';
if ($selectedEstateGuardian === '' && !empty($estateGuardians)) {
    $selectedEstateGuardian = trim((string)($estateGuardians[0]['guardian_username'] ?? ''));
}

$guardianNominations = [];
try {
    $stmt = $pdo->prepare("SELECT g.owner_username, g.guardian_username, g.status, p.bond_amount_mtk, p.guardian_quorum, COALESCE(s.amount_mtk, 0) AS staked_mtk, COALESCE(s.status, '') AS stake_status
        FROM benefactor_estate_guardians g
        JOIN benefactor_estate_plans p ON p.owner_username = g.owner_username
        LEFT JOIN benefactor_estate_guardian_stakes s ON s.owner_username = g.owner_username AND s.guardian_username = g.guardian_username
        WHERE g.guardian_username = ? AND g.status = 'active'
        ORDER BY g.created_at DESC
        LIMIT 100");
    $stmt->execute([$username]);
    $guardianNominations = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $guardianNominations = []; }

$guardianClaims = mh_estate_claims_for_guardian($pdo, $username);

$myEstateClaims = [];
try {
    $stmt = $pdo->prepare("SELECT id, owner_username, beneficiary_username, status, kyc_room_id, proof_verified_at, created_at, challenge_until, halted_at, executed_at
        FROM benefactor_estate_claims
        WHERE beneficiary_username = ?
        ORDER BY created_at DESC
        LIMIT 50");
    $stmt->execute([$username]);
    $myEstateClaims = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $myEstateClaims = []; }

$claimsAgainstMe = [];
try {
    $stmt = $pdo->prepare("SELECT id, owner_username, beneficiary_username, status, kyc_room_id, proof_verified_at, created_at, challenge_until
        FROM benefactor_estate_claims
        WHERE owner_username = ? AND status IN ('pending','approved')
        ORDER BY created_at DESC
        LIMIT 50");
    $stmt->execute([$username]);
    $claimsAgainstMe = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $claimsAgainstMe = []; }

$ownerEstateFlags = [];
try {
    $owners = [];
    foreach ($activeAppointments as $a) {
        $ou = isset($a['owner_username']) ? trim((string)$a['owner_username']) : '';
        if ($ou !== '') $owners[$ou] = true;
    }
    $owners = array_keys($owners);
    if (!empty($owners)) {
        $ph = implode(',', array_fill(0, count($owners), '?'));
        $stmt = $pdo->prepare("SELECT owner_username, inactivity_days, last_checkin_at FROM benefactor_estate_plans WHERE owner_username IN ($ph)");
        $stmt->execute($owners);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $ou = isset($r['owner_username']) ? trim((string)$r['owner_username']) : '';
            if ($ou === '') continue;
            $map[$ou] = $r;
        }
        foreach ($owners as $ou) {
            $p = $map[$ou] ?? null;
            if (!is_array($p)) { $ownerEstateFlags[$ou] = false; continue; }
            $ownerEstateFlags[$ou] = mh_estate_is_inactive($p);
        }
    }
} catch (Throwable $e) { $ownerEstateFlags = []; }

$selectedBenefactor = isset($_GET['benefactor']) ? trim((string)$_GET['benefactor']) : '';
if ($selectedBenefactor === '' && !empty($benefactorsOwned)) {
    $selectedBenefactor = trim((string)($benefactorsOwned[0]['benefactor_username'] ?? ''));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Benefactors</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        .page-wrap { max-width: 1200px; margin: 0 auto; padding: 28px 18px; }
        .card { background: rgba(20, 20, 25, 0.6); border: 1px solid rgba(255,255,255,0.12); border-radius: 16px; padding: 18px; }
        h1 { margin:0 0 8px 0; color: var(--theme-primary, #00d4ff); font-family:'Orbitron',sans-serif; }
        .muted { color:#9aa; font-size: 12px; }
        .grid { display:grid; grid-template-columns: 1fr; gap: 14px; }
        .mh-table { width: 100%; border-collapse: collapse; }
        .mh-table th, .mh-table td { text-align:left; padding: 10px 12px; border-bottom: 1px solid rgba(0, 212, 255, 0.15); font-size: 0.95rem; color: rgba(255,255,255,0.9); vertical-align: top; }
        .mh-table th { color: var(--theme-primary, #00d4ff); font-weight:700; }
        .mh-inline { display:flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .mh-badge { display:inline-block; padding: 4px 10px; border-radius: 999px; border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); font-size: 12px; margin: 2px 6px 2px 0; }
        .mh-btn { padding: 10px 14px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; cursor:pointer; font-weight:700; }
        .mh-btn-primary { background: #0aa0b6; border-color: rgba(0,212,255,0.35); }
        .mh-btn-danger { background: rgba(239, 68, 68, 0.85); color:#fff; border-color: rgba(239,68,68,0.5); }
        .mh-btn-good { background: rgba(16,185,129,0.85); color:#fff; border-color: rgba(16,185,129,0.5); }
        .mh-field label { display:block; margin: 12px 0 6px; color:#cfefff; font-size: 12px; }
        .mh-field input, .mh-field select { width: 100%; box-sizing:border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
        .mh-note { margin-top: 8px; font-size: 12px; color:#9aa; }
        .mh-status-ok { color: rgba(16,185,129,0.95); font-size: 12px; }
        .mh-status-err { color: rgba(239,68,68,0.95); font-size: 12px; }
        .mh-valid { border-color: rgba(16,185,129,0.55) !important; }
        .mh-invalid { border-color: rgba(239,68,68,0.55) !important; }
        .mh-alert { margin-bottom: 12px; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.2); background: rgba(0,212,255,0.06); }
        .mh-alert.err { border-color: rgba(239,68,68,0.35); background: rgba(239,68,68,0.10); }
        .mh-modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.65); display: none; align-items: center; justify-content: center; padding: 18px; z-index: 9999; }
        .mh-modal { width: 100%; max-width: 780px; background: rgba(20, 20, 25, 0.95); border: 1px solid rgba(0, 212, 255, 0.25); border-radius: 16px; padding: 18px; }
        .mh-modal h3 { margin: 0 0 12px 0; color: var(--theme-primary, #00d4ff); font-family: 'Orbitron', sans-serif; }
        .mh-modal .mh-actions { display:flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; margin-top: 12px; }
        .mh-two { display:grid; grid-template-columns: 1fr; gap: 14px; }
        @media (min-width: 900px) { .mh-two { grid-template-columns: 340px 1fr; } }
        .mh-list { display:flex; flex-direction: column; gap: 8px; }
        .mh-list a { display:block; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(0,212,255,0.16); background: rgba(0,0,0,0.20); color: rgba(255,255,255,0.92); text-decoration:none; }
        .mh-list a.active { border-color: rgba(0,212,255,0.42); background: rgba(0,212,255,0.08); }
        .mh-rule-row { display:grid; grid-template-columns: 1fr 160px 120px; gap: 10px; align-items: center; padding: 10px 0; border-bottom: 1px solid rgba(0, 212, 255, 0.12); }
        .mh-rule-row:last-child { border-bottom: 0; }
        .mh-save { font-size: 12px; color:#9aa; min-height: 16px; }
    </style>
</head>
<body class="hub-page">
<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content">
    <div class="page-wrap">
        <h1>Benefactors</h1>
        <div class="muted">Appointments, claims, and verified transfers.</div>

        <?php if ($message !== ''): ?>
            <div class="mh-alert err"><?php echo htmlspecialchars($message, ENT_QUOTES); ?></div>
        <?php elseif (isset($_SESSION['hub_settings_flash_message']) && is_string($_SESSION['hub_settings_flash_message'])): ?>
            <div class="mh-alert"><?php echo htmlspecialchars((string)$_SESSION['hub_settings_flash_message'], ENT_QUOTES); unset($_SESSION['hub_settings_flash_message']); ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Your Benefactors (Owner)</div>

                <?php if (!empty($ownerAllocationSummary)): ?>
                    <?php
                        $anyUnassigned = false;
                        foreach ($ownerAllocationSummary as $s) {
                            if (((float)($s['unassigned_pct'] ?? 0)) > 0.000001) { $anyUnassigned = true; break; }
                        }
                    ?>
                    <div class="mh-alert<?php echo $anyUnassigned ? ' err' : ''; ?>">
                        <div style="font-weight:800; margin-bottom: 8px;">Asset Assignment Summary</div>
                        <div class="muted" style="margin-bottom: 10px;">Shows how much of each asset type is assigned to benefactors vs unassigned.</div>
                        <table class="mh-table" style="margin-top: 0;">
                            <thead>
                                <tr>
                                    <th>Asset</th>
                                    <th>Total</th>
                                    <th>Assigned</th>
                                    <th>Unassigned</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ownerAllocationSummary as $s): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($s['label'] ?? ''), ENT_QUOTES); ?></td>
                                        <td><?php echo number_format((int)($s['qty'] ?? 0)); ?></td>
                                        <td><?php echo number_format((int)($s['assigned_qty'] ?? 0)); ?> <span class="muted">(<?php echo number_format((float)($s['assigned_pct'] ?? 0), 2); ?>%)</span></td>
                                        <td><?php echo number_format((int)($s['unassigned_qty'] ?? 0)); ?> <span class="muted">(<?php echo number_format((float)($s['unassigned_pct'] ?? 0), 2); ?>%)</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if (empty($benefactorsOwned)): ?>
                    <div class="muted">No benefactors assigned.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Your Benefactor Appointments (Benefactor)</div>
                <div class="muted" style="margin-bottom: 12px;">No amounts are shown until you have verified liveness for the claim.</div>

                <?php if (!empty($pendingAppointments)): ?>
                    <div class="muted" style="margin-bottom: 8px;">Pending appointment requests</div>
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
                                <td class="muted"><?php echo htmlspecialchars($ou, ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo htmlspecialchars((string)($o['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                <td>
                                    <div class="mh-inline">
                                        <form method="post">
                                            <input type="hidden" name="action" value="benefactor_appointment_decide">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                            <input type="hidden" name="decision" value="accept">
                                            <button type="submit" class="mh-btn">Accept Appointment</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="benefactor_appointment_decide">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                            <input type="hidden" name="decision" value="deny">
                                            <button type="submit" class="mh-btn mh-btn-danger">Decline</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (!empty($activeAppointments)): ?>
                    <div class="muted" style="margin-bottom: 8px;">Active appointments</div>
                    <table class="mh-table" style="margin-bottom: 14px;">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Allocated Types</th>
                                <th>Claim</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeAppointments as $o): ?>
                            <?php
                                $ou = (string)($o['owner_username'] ?? '');
                                $types = [];
                                try {
                                    $stmt = $pdo->prepare("SELECT DISTINCT asset_type FROM benefactor_asset_rules WHERE owner_username = ? AND benefactor_username = ?");
                                    $stmt->execute([$ou, $username]);
                                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                    foreach ($rows as $r) {
                                        $t = isset($r['asset_type']) ? trim((string)$r['asset_type']) : '';
                                        if ($t !== '') $types[] = $t;
                                    }
                                } catch (Throwable $e) { $types = []; }
                                $inactive = $ou !== '' && isset($ownerEstateFlags[$ou]) ? (bool)$ownerEstateFlags[$ou] : false;
                            ?>
                            <tr>
                                <td class="muted">
                                    <?php echo htmlspecialchars($ou, ENT_QUOTES); ?>
                                    <?php if ($inactive): ?>
                                        <div class="muted" style="margin-top:4px; color: rgba(239,68,68,0.95);">Owner inactive (dead-man eligible)</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($types)): ?>
                                        <?php foreach ($types as $t): ?>
                                            <span class="mh-badge"><?php echo htmlspecialchars($labelForAsset((string)$t), ENT_QUOTES); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="muted">No allocations</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="mh-inline">
                                        <form method="post">
                                            <input type="hidden" name="action" value="benefactor_claim_initiate">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                            <button type="submit" class="mh-btn">Create Claim</button>
                                        </form>
                                        <?php if ($inactive): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="estate_claim_create">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                                <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                                <button type="submit" class="mh-btn mh-btn-danger">Dead-man Claim</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted">No active appointments.</div>
                <?php endif; ?>

                <?php if (!empty($myClaims)): ?>
                    <div class="muted" style="margin-bottom: 8px;">Your initiated claims</div>
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
                                <td class="muted"><?php echo htmlspecialchars((string)($c['owner_username'] ?? ''), ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo htmlspecialchars((string)($c['status'] ?? ''), ENT_QUOTES); ?></td>
                                <td>
                                    <div class="mh-inline">
                                        <?php if (!empty($c['kyc_room_id'])): ?>
                                            <a class="mh-btn mh-btn-primary" style="text-decoration:none;" href="/auth/id/capture.php?room_id=<?php echo urlencode((string)$c['kyc_room_id']); ?>&k=mosip&return_url=<?php echo rawurlencode('/hub/equity/benefactors.php'); ?>">Upload Proof</a>
                                        <?php endif; ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="benefactor_claim_execute">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="claim_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                            <button type="submit" class="mh-btn">Execute Transfer</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="mh-note">Execute Transfer requires verified liveness for your claim proof room.</div>
                <?php endif; ?>
            </div>

            <div class="card">
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Benefactors</div>

                <?php if (!empty($benefactorsOwned)): ?>
                    <div class="mh-two">
                        <div>
                            <div class="muted" style="margin-bottom: 10px;">Select a benefactor to manage.</div>
                            <div class="mh-list">
                                <?php foreach ($benefactorsOwned as $b): ?>
                                    <?php
                                        $bu = trim((string)($b['benefactor_username'] ?? ''));
                                        if ($bu === '') continue;
                                        $bst = strtoupper(trim((string)($b['status'] ?? '')));
                                        $bname = trim((string)($b['benefactor_name'] ?? ''));
                                        $isSel = ($selectedBenefactor !== '' && strcasecmp($selectedBenefactor, $bu) === 0);
                                    ?>
                                    <a href="/hub/equity/benefactors.php?benefactor=<?php echo rawurlencode($bu); ?>" data-bu="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>" class="<?php echo $isSel ? 'active' : ''; ?>">
                                        <div style="display:flex; justify-content: space-between; gap: 10px; align-items:center;">
                                            <div style="min-width: 0;">
                                                <div style="font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($bname !== '' ? $bname : $bu, ENT_QUOTES); ?></div>
                                                <div class="muted" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($bu, ENT_QUOTES); ?></div>
                                            </div>
                                            <div class="mh-badge"><?php echo htmlspecialchars($bst !== '' ? $bst : 'ACTIVE', ENT_QUOTES); ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <div id="mhBenefactorPanels">
                                <?php foreach ($benefactorsOwned as $b): ?>
                                    <?php
                                        $bu = trim((string)($b['benefactor_username'] ?? ''));
                                        if ($bu === '') continue;
                                        $selName = trim((string)($b['benefactor_name'] ?? ''));
                                        $selStatus = strtoupper(trim((string)($b['status'] ?? '')));
                                        $isSel = ($selectedBenefactor !== '' && strcasecmp($selectedBenefactor, $bu) === 0);
                                    ?>
                                    <div class="mh-benefactor-panel" data-bu="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>" style="<?php echo $isSel ? '' : 'display:none;'; ?>">
                                        <div style="display:flex; justify-content: space-between; gap: 12px; align-items:flex-end; flex-wrap:wrap;">
                                            <div>
                                                <div style="font-weight:800;"><?php echo htmlspecialchars($selName !== '' ? $selName : $bu, ENT_QUOTES); ?></div>
                                                <div class="muted"><?php echo htmlspecialchars($bu, ENT_QUOTES); ?> · <?php echo htmlspecialchars($selStatus !== '' ? $selStatus : 'ACTIVE', ENT_QUOTES); ?></div>
                                            </div>
                                            <form method="post" onsubmit="return window.confirm('Remove this benefactor?');">
                                                <input type="hidden" name="action" value="benefactor_delete">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                                <input type="hidden" name="benefactor_username" value="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>">
                                                <button type="submit" class="mh-btn mh-btn-danger">Delete</button>
                                            </form>
                                        </div>

                                        <div class="mh-save mhRuleSaveStatus" data-bu="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>" style="margin-top: 10px;"></div>

                                        <div style="margin-top: 12px;">
                                            <?php foreach ($benefactorAssetsOwner as $a): ?>
                                                <?php
                                                    $at = (string)($a['type'] ?? '');
                                                    if ($at === '') continue;
                                                    $label = (string)($a['label'] ?? $at);
                                                    $r = $rulesByUser[$bu][$at] ?? null;
                                                    $mode = is_array($r) ? strtolower((string)($r['mode'] ?? 'equal')) : 'equal';
                                                    $val = is_array($r) && isset($r['value_num']) ? (string)$r['value_num'] : '';
                                                ?>
                                                <div class="mh-rule-row" data-bu="<?php echo htmlspecialchars($bu, ENT_QUOTES); ?>" data-asset="<?php echo htmlspecialchars($at, ENT_QUOTES); ?>">
                                                    <div class="muted"><?php echo htmlspecialchars($label, ENT_QUOTES); ?></div>
                                                    <select class="mhRuleMode">
                                                        <option value="equal"<?php echo $mode === 'equal' ? ' selected' : ''; ?>>Equal split</option>
                                                        <option value="percent"<?php echo $mode === 'percent' ? ' selected' : ''; ?>>Percent</option>
                                                        <option value="all"<?php echo $mode === 'all' ? ' selected' : ''; ?>>All (split)</option>
                                                    </select>
                                                    <input class="mhRuleValue" type="number" min="0" max="100" step="0.000001" placeholder="%" value="<?php echo htmlspecialchars($val, ENT_QUOTES); ?>" <?php echo $mode === 'percent' ? '' : 'disabled'; ?> />
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="muted">No benefactors assigned.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Add Benefactor</div>
                <div class="mh-note">The Real Name, Real Surname, and Username of the benefactor must match the user account exactly.</div>
                <form method="post" id="mhAddBenefactorForm">
                    <input type="hidden" name="action" value="benefactor_add">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <div class="mh-inline" style="gap: 12px; flex-wrap: wrap;">
                        <div class="mh-field" style="flex:1; min-width: 240px;">
                            <label>Real Name</label>
                            <input type="text" name="benefactor_real_first_name" id="mhBenefactorFirst" placeholder="Real Name" autocomplete="off">
                        </div>
                        <div class="mh-field" style="flex:1; min-width: 240px;">
                            <label>Real Surname</label>
                            <input type="text" name="benefactor_real_last_name" id="mhBenefactorLast" placeholder="Real Surname" autocomplete="off">
                        </div>
                    </div>
                    <div class="mh-field">
                        <label>Username</label>
                        <input type="text" name="benefactor_username" id="mhBenefactorUsername" placeholder="Username" autocomplete="off">
                        <div class="mh-note">Must exist on platform and have at least <?php echo (int)mh_benefactors_min_tokens_required(); ?> MTK.</div>
                    </div>
                    <div class="mh-inline" style="margin-top: 10px; justify-content: space-between;">
                        <div class="muted" id="mhBenefactorCheck">Enter name + username to validate.</div>
                        <button type="submit" id="mhBenefactorAddBtn" class="mh-btn mh-btn-danger" disabled>Add Benefactor</button>
                    </div>
                    <div class="mh-inline" id="mhTokenActions" style="margin-top: 10px; display:none; gap: 10px;">
                        <a class="mh-btn" href="/hub/tokens/tokens.php" target="_blank" rel="noopener">Request/Transfer Tokens</a>
                        <button type="button" class="mh-btn mh-btn-primary" id="mhBuyTokensBtn">Buy Tokens</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Guardian Dashboard</div>
                <div class="muted" style="margin-bottom: 12px;">Lock stake to participate. To approve a dead-man claim, your account must be KYC verified via <a href="/auth/id/" style="color: var(--theme-primary, #00d4ff);">/auth/id/</a>.</div>

                <?php if (!empty($guardianNominations)): ?>
                    <div class="muted" style="margin-bottom: 8px;">Your nominations</div>
                    <table class="mh-table" style="margin-bottom: 14px;">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Bond</th>
                                <th>Your stake</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($guardianNominations as $n): ?>
                            <?php
                                $ou = (string)($n['owner_username'] ?? '');
                                $bond = (int)($n['bond_amount_mtk'] ?? 0);
                                $staked = (int)($n['staked_mtk'] ?? 0);
                                $sst = (string)($n['stake_status'] ?? '');
                            ?>
                            <tr>
                                <td class="muted"><?php echo htmlspecialchars($ou, ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo number_format($bond); ?> MTK</td>
                                <td class="muted"><?php echo number_format($staked); ?> MTK <span class="muted">(<?php echo htmlspecialchars($sst !== '' ? $sst : 'none', ENT_QUOTES); ?>)</span></td>
                                <td>
                                    <form method="post">
                                        <input type="hidden" name="action" value="estate_guardian_stake_lock">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                        <input type="hidden" name="owner_username" value="<?php echo htmlspecialchars($ou, ENT_QUOTES); ?>">
                                        <button type="submit" class="mh-btn">Lock Stake</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted" style="margin-bottom: 12px;">No nominations.</div>
                <?php endif; ?>

                <?php if (!empty($guardianClaims)): ?>
                    <div class="muted" style="margin-bottom: 8px;">Pending dead-man claims requiring your approval</div>
                    <table class="mh-table">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Beneficiary</th>
                                <th>Challenge until</th>
                                <th>Vote</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($guardianClaims as $c): ?>
                            <?php
                                $cid = (int)($c['id'] ?? 0);
                                $ou = (string)($c['owner_username'] ?? '');
                                $bu = (string)($c['beneficiary_username'] ?? '');
                                $cu = (string)($c['challenge_until'] ?? '');
                            ?>
                            <tr>
                                <td class="muted"><?php echo htmlspecialchars($ou, ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo htmlspecialchars($bu, ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo htmlspecialchars($cu, ENT_QUOTES); ?></td>
                                <td>
                                    <div class="mh-inline">
                                        <?php if (!$guardianKycOk): ?>
                                            <a class="mh-btn mh-btn-primary" style="text-decoration:none;" href="/auth/id/">Verify KYC</a>
                                            <span class="muted">KYC required before voting</span>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="estate_guardian_vote">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                                <input type="hidden" name="claim_id" value="<?php echo $cid; ?>">
                                                <input type="hidden" name="decision" value="accept">
                                                <button type="submit" class="mh-btn">Approve</button>
                                            </form>
                                            <form method="post">
                                                <input type="hidden" name="action" value="estate_guardian_vote">
                                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                                <input type="hidden" name="claim_id" value="<?php echo $cid; ?>">
                                                <input type="hidden" name="decision" value="reject">
                                                <button type="submit" class="mh-btn mh-btn-danger">Reject</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted">No pending dead-man claims.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Guardian Settings</div>
                <div class="muted" style="margin-bottom: 12px;">Required bond per guardian: <?php echo number_format((int)($estatePlan['bond_amount_mtk'] ?? 0)); ?> MTK</div>

                <?php if (!empty($estateGuardians)): ?>
                    <div class="mh-two" id="mhEstateGuardianWrap">
                        <div>
                            <div class="muted" style="margin-bottom: 10px;">Select a guardian to view details.</div>
                            <div class="mh-list" id="mhEstateGuardianList">
                                <?php foreach ($estateGuardians as $g): ?>
                                    <?php
                                        $gu = (string)($g['guardian_username'] ?? '');
                                        if ($gu === '') continue;
                                        $st = strtoupper(strtolower(trim((string)($g['status'] ?? 'active'))));
                                        $isSel = ($selectedEstateGuardian !== '' && strcasecmp($selectedEstateGuardian, $gu) === 0);
                                    ?>
                                    <a href="/hub/equity/benefactors.php?estate_guardian=<?php echo rawurlencode($gu); ?>" data-gu="<?php echo htmlspecialchars($gu, ENT_QUOTES); ?>" class="<?php echo $isSel ? 'active' : ''; ?>">
                                        <div style="display:flex; justify-content: space-between; gap: 10px; align-items:center;">
                                            <div style="min-width: 0;">
                                                <div style="font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo htmlspecialchars($gu, ENT_QUOTES); ?></div>
                                            </div>
                                            <div class="mh-badge"><?php echo htmlspecialchars($st !== '' ? $st : 'ACTIVE', ENT_QUOTES); ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div id="mhEstateGuardianPanels">
                            <?php foreach ($estateGuardians as $g): ?>
                                <?php
                                    $gu = (string)($g['guardian_username'] ?? '');
                                    if ($gu === '') continue;
                                    $st = strtoupper(strtolower(trim((string)($g['status'] ?? 'active'))));
                                    $staked = (int)($g['staked_mtk'] ?? 0);
                                    $sst = (string)($g['stake_status'] ?? '');
                                    $isSel = ($selectedEstateGuardian !== '' && strcasecmp($selectedEstateGuardian, $gu) === 0);
                                ?>
                                <div class="mh-estate-guardian-panel" data-gu="<?php echo htmlspecialchars($gu, ENT_QUOTES); ?>" style="<?php echo $isSel ? '' : 'display:none;'; ?>">
                                    <div style="display:flex; justify-content: space-between; gap: 12px; align-items:flex-end; flex-wrap:wrap;">
                                        <div>
                                            <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 6px;">Guardian</div>
                                            <div style="font-weight:800;"><?php echo htmlspecialchars($gu, ENT_QUOTES); ?></div>
                                            <div class="muted">Status: <?php echo htmlspecialchars($st !== '' ? $st : 'ACTIVE', ENT_QUOTES); ?></div>
                                        </div>
                                        <form method="post" onsubmit="return window.confirm('Remove this guardian?');">
                                            <input type="hidden" name="action" value="estate_guardian_remove">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="guardian_username" value="<?php echo htmlspecialchars($gu, ENT_QUOTES); ?>">
                                            <button type="submit" class="mh-btn mh-btn-danger">Remove</button>
                                        </form>
                                    </div>
                                    <div class="mh-alert" style="margin-top: 12px;">
                                        <div class="muted">Required bond (from plan)</div>
                                        <div style="font-weight:800;"><?php echo number_format((int)($estatePlan['bond_amount_mtk'] ?? 0)); ?> MTK</div>
                                        <div class="muted" style="margin-top: 10px;">Locked stake (guardian has locked)</div>
                                        <div style="font-weight:800;"><?php echo number_format($staked); ?> MTK <span class="muted">(<?php echo htmlspecialchars($sst !== '' ? $sst : 'none', ENT_QUOTES); ?>)</span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="muted">No guardians nominated.</div>
                <?php endif; ?>

                <form method="post" style="margin-top: 10px;" id="mhGuardianAddForm">
                    <input type="hidden" name="action" value="estate_guardian_add">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <div class="mh-note">Guardian must have Real Name and Real Surname set in account settings and must meet the MTK minimum.</div>
                    <div class="mh-inline" style="gap: 12px; flex-wrap: wrap;">
                        <div class="mh-field" style="flex:1; min-width: 240px;">
                            <label>Real Name</label>
                            <input type="text" name="guardian_real_first_name" id="mhGuardianFirst" placeholder="Real Name" autocomplete="off">
                        </div>
                        <div class="mh-field" style="flex:1; min-width: 240px;">
                            <label>Real Surname</label>
                            <input type="text" name="guardian_real_last_name" id="mhGuardianLast" placeholder="Real Surname" autocomplete="off">
                        </div>
                        <div class="mh-field" style="flex:1; min-width: 240px;">
                            <label>Username</label>
                            <input type="text" name="guardian_username" id="mhGuardianUsername" placeholder="username" autocomplete="off">
                        </div>
                        <div class="mh-field" style="align-self:flex-end;">
                            <button type="submit" class="mh-btn" id="mhGuardianAddBtn" disabled>Add Guardian</button>
                        </div>
                    </div>
                    <div class="muted" id="mhGuardianCheck" style="margin-top: 8px;">Enter real name + real surname + username to validate.</div>
                </form>

                <?php if (!empty($claimsAgainstMe)): ?>
                    <div style="margin-top: 14px;">
                        <div class="muted" style="margin-bottom: 8px;">Dead-man claims against you (Passkey required to halt during challenge window)</div>
                        <table class="mh-table">
                            <thead>
                                <tr>
                                    <th>Beneficiary</th>
                                    <th>Status</th>
                                    <th>Challenge Until</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($claimsAgainstMe as $c): ?>
                                <?php
                                    $cid = (int)($c['id'] ?? 0);
                                    $ben = (string)($c['beneficiary_username'] ?? '');
                                    $st = strtoupper(trim((string)($c['status'] ?? '')));
                                    $cu = (string)($c['challenge_until'] ?? '');
                                ?>
                                <tr>
                                    <td class="muted"><?php echo htmlspecialchars($ben, ENT_QUOTES); ?></td>
                                    <td class="muted"><?php echo htmlspecialchars($st, ENT_QUOTES); ?></td>
                                    <td class="muted"><?php echo htmlspecialchars($cu, ENT_QUOTES); ?></td>
                                    <td>
                                        <button type="button" class="mh-btn mh-btn-danger mhEstateHaltBtn" data-claim-id="<?php echo $cid; ?>">Halt (Passkey)</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 10px;">Your Dead-man Claims</div>
                <div class="muted" style="margin-bottom: 12px;">Vesting releases over time after guardian quorum and challenge window.</div>
                <?php if (!empty($myEstateClaims)): ?>
                    <table class="mh-table">
                        <thead>
                            <tr>
                                <th>Owner</th>
                                <th>Status</th>
                                <th>Proof</th>
                                <th>Challenge Until</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($myEstateClaims as $c): ?>
                            <?php
                                $roomId = (string)($c['kyc_room_id'] ?? '');
                                $proofAt = (string)($c['proof_verified_at'] ?? '');
                                $proofOk = trim($proofAt) !== '';
                            ?>
                            <tr>
                                <td class="muted"><?php echo htmlspecialchars((string)($c['owner_username'] ?? ''), ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo htmlspecialchars(strtoupper((string)($c['status'] ?? '')), ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo $proofOk ? 'VERIFIED' : 'REQUIRED'; ?></td>
                                <td class="muted"><?php echo htmlspecialchars((string)($c['challenge_until'] ?? ''), ENT_QUOTES); ?></td>
                                <td class="muted"><?php echo htmlspecialchars((string)($c['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                <td>
                                    <?php if (!$proofOk && $roomId !== ''): ?>
                                        <a class="mh-btn mh-btn-primary" style="text-decoration:none;" href="/auth/id/capture.php?room_id=<?php echo urlencode($roomId); ?>&k=mosip&return_url=<?php echo rawurlencode('/hub/equity/benefactors.php'); ?>">Upload Proof</a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="muted">No dead-man claims.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap: 12px; flex-wrap: wrap;">
                    <div>
                        <div style="font-family:'Orbitron',sans-serif; color: var(--theme-primary, #00d4ff); margin-bottom: 6px;">Estate Plan (Dead-man Switch)</div>
                        <div class="muted">Last check-in: <?php echo htmlspecialchars((string)($estatePlan['last_checkin_at'] ?? ''), ENT_QUOTES); ?> · Status: <span style="color:<?php echo $estateInactive ? 'rgba(239,68,68,0.95)' : 'rgba(16,185,129,0.95)'; ?>; font-weight:800;"><?php echo $estateInactive ? 'INACTIVE' : 'ACTIVE'; ?></span></div>
                        <div style="margin-top: 10px;">
                            <button type="button" class="mh-btn" id="mhEstateCheckinBtn">Check in to invalidate claims</button>
                        </div>
                    </div>
                </div>

                <form method="post" style="margin-top: 12px;" id="mhEstatePlanForm">
                    <input type="hidden" name="action" value="estate_plan_save">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <div class="mh-inline" style="gap: 12px;">
                        <div class="mh-field" style="min-width: 200px;">
                            <label>Inactivity Days</label>
                            <input type="number" name="inactivity_days" min="1" step="1" value="<?php echo (int)($estatePlan['inactivity_days'] ?? 90); ?>">
                        </div>
                        <div class="mh-field" style="min-width: 200px;">
                            <label>Challenge Days</label>
                            <input type="number" name="challenge_days" min="1" step="1" value="<?php echo (int)($estatePlan['challenge_days'] ?? 7); ?>">
                        </div>
                        <div class="mh-field" style="min-width: 200px;">
                            <label>Guardian Quorum (M)</label>
                            <input type="number" name="guardian_quorum" min="1" step="1" value="<?php echo (int)($estatePlan['guardian_quorum'] ?? 2); ?>">
                        </div>
                        <div class="mh-field" style="min-width: 200px;">
                            <label>Bond Amount (MTK)</label>
                            <input type="number" name="bond_amount_mtk" min="0" step="1" value="<?php echo (int)($estatePlan['bond_amount_mtk'] ?? 1000); ?>">
                        </div>
                        <div class="mh-field" style="min-width: 200px;">
                            <label>Release Parts (Tranches)</label>
                            <input type="number" name="tranche_count" min="1" step="1" value="<?php echo (int)($estatePlan['tranche_count'] ?? 6); ?>">
                            <div class="muted">How many separate releases the beneficiary receives after approval. Example: 6 means 6 smaller releases instead of 1 large transfer.</div>
                        </div>
                        <div class="mh-field" style="min-width: 200px;">
                            <label>Days Between Releases</label>
                            <input type="number" name="tranche_interval_days" min="1" step="1" value="<?php echo (int)($estatePlan['tranche_interval_days'] ?? 30); ?>">
                            <div class="muted">How many days to wait between each release. Example: 30 means one release every 30 days.</div>
                        </div>
                        <div class="mh-field" style="align-self:flex-end;">
                            <button type="submit" class="mh-btn mh-btn-primary">Save Plan</button>
                        </div>
                    </div>
                    <div class="mh-save" id="mhEstateSaveStatus" style="margin-top: 10px;"></div>
                </form>
            </div>
        </div>
    </div>
</main>

<?php if (!empty($pendingClaimsComputed)): ?>
    <div class="mh-modal-backdrop" id="mhBenefactorModal" style="display:flex;">
        <div class="mh-modal">
            <h3>Benefactor Allocation Request</h3>
            <div class="muted" style="margin-bottom: 10px;">Complete liveness verification to reveal amounts and accept/deny.</div>
            <table class="mh-table">
                <thead>
                    <tr>
                        <th>Owner</th>
                        <th>Allocated Types</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingClaimsComputed as $p): ?>
                    <tr>
                        <td>
                            <div class="muted"><?php echo htmlspecialchars((string)($p['owner_username'] ?? ''), ENT_QUOTES); ?></div>
                            <div class="muted">Initiated by: <?php echo htmlspecialchars((string)($p['initiated_by'] ?? ''), ENT_QUOTES); ?></div>
                        </td>
                        <td>
                            <?php foreach (($p['asset_types'] ?? []) as $t): ?>
                                <span class="mh-badge"><?php echo htmlspecialchars($labelForAsset((string)$t), ENT_QUOTES); ?></span>
                            <?php endforeach; ?>
                            <?php if (!empty($p['proof_verified']) && !empty($p['allocation_preview']) && is_array($p['allocation_preview'])): ?>
                                <div class="muted" style="margin-top: 8px;">Verified allocation preview (your share):</div>
                                <?php foreach ($p['allocation_preview'] as $t => $amt): ?>
                                    <div class="muted"><?php echo htmlspecialchars($labelForAsset((string)$t), ENT_QUOTES); ?>: <?php echo number_format((int)$amt); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="mh-inline">
                                <?php if (!empty($p['kyc_room_id'])): ?>
                                    <a class="mh-btn mh-btn-primary" style="text-decoration:none;" href="/auth/id/capture.php?room_id=<?php echo urlencode((string)$p['kyc_room_id']); ?>&k=mosip&return_url=<?php echo rawurlencode('/hub/equity/benefactors.php'); ?>">Upload Proof</a>
                                <?php endif; ?>
                                <?php if (!empty($p['proof_verified'])): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="benefactor_claim_decide">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                        <input type="hidden" name="claim_id" value="<?php echo (int)($p['claim_id'] ?? 0); ?>">
                                        <input type="hidden" name="decision" value="accept">
                                        <button type="submit" class="mh-btn">Accept</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="benefactor_claim_decide">
                                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                        <input type="hidden" name="claim_id" value="<?php echo (int)($p['claim_id'] ?? 0); ?>">
                                        <input type="hidden" name="decision" value="deny">
                                        <button type="submit" class="mh-btn mh-btn-danger">Deny</button>
                                    </form>
                                <?php else: ?>
                                    <span class="muted">Awaiting verified liveness</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="mh-actions">
                <button type="button" class="mh-btn" onclick="document.getElementById('mhBenefactorModal').style.display='none'">Close</button>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
<div class="mh-modal-backdrop" id="mhBuyTokensModal" style="display:none;">
    <div class="mh-modal" style="max-width: 980px;">
        <div style="display:flex; justify-content:space-between; align-items:center; gap: 12px;">
            <h3 style="margin:0;">Buy MTK Tokens</h3>
            <button type="button" class="mh-btn" id="mhBuyTokensClose">Close</button>
        </div>
        <div style="margin-top: 12px;">
            <iframe id="mhBuyTokensFrame" src="/hub/genesis/tokenization.php" style="width:100%; height: 72vh; border: 0; border-radius: 12px; background: rgba(0,0,0,0.35);"></iframe>
        </div>
    </div>
</div>
<script>
(() => {
  const firstEl = document.getElementById('mhBenefactorFirst');
  const lastEl = document.getElementById('mhBenefactorLast');
  const userEl = document.getElementById('mhBenefactorUsername');
  const btn = document.getElementById('mhBenefactorAddBtn');
  const out = document.getElementById('mhBenefactorCheck');
  const actions = document.getElementById('mhTokenActions');
  const buyBtn = document.getElementById('mhBuyTokensBtn');
  const modal = document.getElementById('mhBuyTokensModal');
  const modalClose = document.getElementById('mhBuyTokensClose');
  const frame = document.getElementById('mhBuyTokensFrame');
  if (!firstEl || !lastEl || !userEl || !btn || !out) return;

  let t = null;

  function clearInputState() {
    firstEl.classList.remove('mh-valid', 'mh-invalid');
    lastEl.classList.remove('mh-valid', 'mh-invalid');
    userEl.classList.remove('mh-valid', 'mh-invalid');
  }

  function showActions(show) {
    if (!actions) return;
    actions.style.display = show ? 'flex' : 'none';
  }

  function openBuyModal() {
    if (!modal) return;
    modal.style.display = 'flex';
    if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === '')) {
      frame.setAttribute('src', '/hub/genesis/tokenization.php');
    }
  }

  function closeBuyModal() {
    if (!modal) return;
    modal.style.display = 'none';
  }

  if (buyBtn) buyBtn.addEventListener('click', openBuyModal);
  if (modalClose) modalClose.addEventListener('click', closeBuyModal);
  if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeBuyModal(); });

  function setState(kind, message) {
    out.classList.remove('mh-status-ok', 'mh-status-err');
    if (kind === 'ok') out.classList.add('mh-status-ok');
    if (kind === 'err') out.classList.add('mh-status-err');
    out.textContent = message;
  }

  async function check() {
    const first = String(firstEl.value || '').trim();
    const last = String(lastEl.value || '').trim();
    const username = String(userEl.value || '').trim();
    if (!first || !last || !username) {
      clearInputState();
      btn.classList.remove('mh-btn-good');
      btn.classList.add('mh-btn-danger');
      btn.disabled = true;
      showActions(false);
      setState('info', 'Enter real name + real surname + username to validate.');
      return;
    }
    const url = `/hub/equity/benefactors.php?ajax=benefactor_lookup&username=${encodeURIComponent(username)}&real_first_name=${encodeURIComponent(first)}&real_last_name=${encodeURIComponent(last)}`;
    let res = null;
    let rawText = '';
    try {
      res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      rawText = await res.text();
    } catch (e) {
      clearInputState();
      btn.classList.remove('mh-btn-good');
      btn.classList.add('mh-btn-danger');
      btn.disabled = false;
      setState('err', 'Auto-validation unavailable. Click Add Benefactor to validate server-side.');
      return;
    }
    let data = null;
    try {
      data = rawText ? JSON.parse(rawText) : null;
    } catch (e) {
      clearInputState();
      btn.classList.remove('mh-btn-good');
      btn.classList.add('mh-btn-danger');
      btn.disabled = false;
      const st = res ? String(res.status) : '0';
      let snippet = rawText ? rawText.replace(/\s+/g, ' ').slice(0, 160) : '';
      snippet = snippet ? ` Response: ${snippet}` : '';
      setState('err', `Auto-validation unavailable (HTTP ${st}). Click Add Benefactor to validate server-side.${snippet}`);
      return;
    }
    if (!res || !res.ok || !data || data.ok !== true) {
      clearInputState();
      btn.classList.remove('mh-btn-good');
      btn.classList.add('mh-btn-danger');
      btn.disabled = false;
      const st = res ? String(res.status) : '0';
      const errMsg = (data && (data.error || data.message)) ? String(data.error || data.message) : '';
      let snippet = '';
      if (rawText) {
        snippet = rawText.replace(/\s+/g, ' ').slice(0, 160);
        snippet = snippet ? ` Response: ${snippet}` : '';
      }
      setState('err', `Auto-validation unavailable (HTTP ${st}${errMsg ? ': ' + errMsg : ''}). Click Add Benefactor to validate server-side.${snippet}`);
      return;
    }
    const exists = !!data.exists;
    const match = !!data.name_match;
    const tok = !!data.tokens_ok;
    const min = Number(data.min_tokens || 0);
    const tokenBal = Number(data.token_balance || 0);

    if (exists && match && tok) {
      firstEl.classList.remove('mh-invalid');
      lastEl.classList.remove('mh-invalid');
      userEl.classList.remove('mh-invalid');
      firstEl.classList.add('mh-valid');
      lastEl.classList.add('mh-valid');
      userEl.classList.add('mh-valid');
      btn.classList.remove('mh-btn-danger');
      btn.classList.add('mh-btn-good');
      btn.disabled = false;
      showActions(false);
      setState('ok', 'Match found. Appointment can be sent.');
      return;
    }
    btn.classList.remove('mh-btn-good');
    btn.classList.add('mh-btn-danger');
    btn.disabled = true;
    firstEl.classList.remove('mh-valid');
    lastEl.classList.remove('mh-valid');
    userEl.classList.remove('mh-valid');
    if (!exists) {
      firstEl.classList.add('mh-invalid');
      lastEl.classList.add('mh-invalid');
      userEl.classList.add('mh-invalid');
      setState('err', 'Fail: Username not found on the platform.');
    } else if (!match) {
      firstEl.classList.add('mh-invalid');
      lastEl.classList.add('mh-invalid');
      userEl.classList.add('mh-valid');
      const hasReal = !!data.has_real_name;
      setState('err', hasReal ? 'Fail: Real Name and Real Surname does not match the user account exactly.' : 'Fail: User has no Real Name / Real Surname set in account settings.');
    } else if (!tok) {
      firstEl.classList.add('mh-valid');
      lastEl.classList.add('mh-valid');
      userEl.classList.add('mh-valid');
      showActions(true);
      setState('err', `Fail: User does not meet MTK requirement (min ${min}). User has ${tokenBal} MTK.`);
    } else {
      firstEl.classList.add('mh-invalid');
      lastEl.classList.add('mh-invalid');
      userEl.classList.add('mh-invalid');
      showActions(false);
      setState('err', 'Fail: Not eligible.');
    }
  }

  function schedule() {
    if (t) clearTimeout(t);
    t = setTimeout(() => { check().catch(() => {}); }, 250);
  }
  firstEl.addEventListener('input', schedule);
  lastEl.addEventListener('input', schedule);
  userEl.addEventListener('input', schedule);
  firstEl.addEventListener('change', schedule);
  lastEl.addEventListener('change', schedule);
  userEl.addEventListener('change', schedule);
  setTimeout(() => { check().catch(() => {}); }, 0);
})();
</script>
<script>
(() => {
  const firstEl = document.getElementById('mhGuardianFirst');
  const lastEl = document.getElementById('mhGuardianLast');
  const userEl = document.getElementById('mhGuardianUsername');
  const btn = document.getElementById('mhGuardianAddBtn');
  const out = document.getElementById('mhGuardianCheck');
  if (!firstEl || !lastEl || !userEl || !btn || !out) return;

  let t = null;

  function clearInputState() {
    firstEl.classList.remove('mh-valid', 'mh-invalid');
    lastEl.classList.remove('mh-valid', 'mh-invalid');
    userEl.classList.remove('mh-valid', 'mh-invalid');
  }

  function setState(kind, message) {
    out.classList.remove('mh-status-ok', 'mh-status-err');
    if (kind === 'ok') out.classList.add('mh-status-ok');
    if (kind === 'err') out.classList.add('mh-status-err');
    out.textContent = message;
  }

  async function check() {
    const first = String(firstEl.value || '').trim();
    const last = String(lastEl.value || '').trim();
    const username = String(userEl.value || '').trim();
    if (!first || !last || !username) {
      clearInputState();
      btn.disabled = true;
      setState('info', 'Enter real name + real surname + username to validate.');
      return;
    }
    const url = `/hub/equity/benefactors.php?ajax=guardian_lookup&username=${encodeURIComponent(username)}&real_first_name=${encodeURIComponent(first)}&real_last_name=${encodeURIComponent(last)}`;
    let res = null;
    let rawText = '';
    try {
      res = await fetch(url, {
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      rawText = await res.text();
    } catch (e) {
      clearInputState();
      btn.disabled = false;
      setState('err', 'Auto-validation unavailable. Click Add Guardian to validate server-side.');
      return;
    }
    let data = null;
    try { data = rawText ? JSON.parse(rawText) : null; } catch (e) { data = null; }
    if (!res || !res.ok || !data || data.ok !== true) {
      clearInputState();
      btn.disabled = false;
      const st = res ? String(res.status) : '0';
      let snippet = rawText ? rawText.replace(/\s+/g, ' ').slice(0, 160) : '';
      snippet = snippet ? ` Response: ${snippet}` : '';
      setState('err', `Auto-validation unavailable (HTTP ${st}). Click Add Guardian to validate server-side.${snippet}`);
      return;
    }

    const exists = !!data.exists;
    const match = !!data.name_match;
    const tok = !!data.tokens_ok;
    const min = Number(data.min_tokens || 0);
    const tokenBal = Number(data.token_balance || 0);

    if (exists && match && tok) {
      firstEl.classList.remove('mh-invalid');
      lastEl.classList.remove('mh-invalid');
      userEl.classList.remove('mh-invalid');
      firstEl.classList.add('mh-valid');
      lastEl.classList.add('mh-valid');
      userEl.classList.add('mh-valid');
      btn.disabled = false;
      setState('ok', 'Match found. Guardian can be added.');
      return;
    }

    btn.disabled = true;
    firstEl.classList.remove('mh-valid');
    lastEl.classList.remove('mh-valid');
    userEl.classList.remove('mh-valid');
    if (!exists) {
      firstEl.classList.add('mh-invalid');
      lastEl.classList.add('mh-invalid');
      userEl.classList.add('mh-invalid');
      setState('err', 'Fail: Username not found on the platform.');
    } else if (!match) {
      firstEl.classList.add('mh-invalid');
      lastEl.classList.add('mh-invalid');
      userEl.classList.add('mh-valid');
      const hasReal = !!data.has_real_name;
      setState('err', hasReal ? 'Fail: Real Name and Real Surname does not match the user account exactly.' : 'Fail: User has no Real Name / Real Surname set in account settings.');
    } else if (!tok) {
      firstEl.classList.add('mh-valid');
      lastEl.classList.add('mh-valid');
      userEl.classList.add('mh-valid');
      setState('err', `Fail: User does not meet MTK requirement (min ${min}). User has ${tokenBal} MTK.`);
    } else {
      firstEl.classList.add('mh-invalid');
      lastEl.classList.add('mh-invalid');
      userEl.classList.add('mh-invalid');
      setState('err', 'Fail: Not eligible.');
    }
  }

  function schedule() {
    if (t) clearTimeout(t);
    t = setTimeout(() => { check().catch(() => {}); }, 250);
  }
  firstEl.addEventListener('input', schedule);
  lastEl.addEventListener('input', schedule);
  userEl.addEventListener('input', schedule);
  firstEl.addEventListener('change', schedule);
  lastEl.addEventListener('change', schedule);
  userEl.addEventListener('change', schedule);
  setTimeout(() => { check().catch(() => {}); }, 0);
})();
</script>
<script>
(() => {
  const csrf = <?php echo json_encode($csrf); ?>;
  if (!csrf) return;

  let t = null;
  let lastKey = '';

  function show(bu, msg, ok) {
    const statusEl = document.querySelector('.mhRuleSaveStatus[data-bu="' + CSS.escape(bu) + '"]');
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.style.color = ok === true ? 'rgba(16,185,129,0.95)' : (ok === false ? 'rgba(239,68,68,0.95)' : '#9aa');
  }

  async function save(bu, asset, mode, valueNum) {
    const body = new URLSearchParams();
    body.set('csrf', csrf);
    body.set('benefactor_username', bu);
    body.set('asset_type', asset);
    body.set('mode', mode);
    if (mode === 'percent') body.set('value_num', String(valueNum || '0'));
    const res = await fetch('/hub/equity/benefactors.php?ajax=benefactor_rule_save', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: body.toString(),
      cache: 'no-store',
    });
    const txt = await res.text();
    let data = null;
    try { data = txt ? JSON.parse(txt) : null; } catch (e) { data = null; }
    if (!res.ok || !data || data.ok !== true) throw new Error('save_failed');
  }

  function bindRow(row) {
    const bu = row.getAttribute('data-bu') || '';
    const asset = row.getAttribute('data-asset') || '';
    const modeEl = row.querySelector('.mhRuleMode');
    const valEl = row.querySelector('.mhRuleValue');
    if (!bu || !asset || !modeEl || !valEl) return;

    function schedule() {
      const mode = String(modeEl.value || 'equal');
      if (mode === 'percent') {
        valEl.disabled = false;
      } else {
        valEl.value = '';
        valEl.disabled = true;
      }
      const valueNum = mode === 'percent' ? String(valEl.value || '0') : '';
      const key = [bu, asset, mode, valueNum].join('|');
      if (key === lastKey) return;
      lastKey = key;
      if (t) clearTimeout(t);
      show(bu, 'Saving...', null);
      t = setTimeout(async () => {
        try {
          await save(bu, asset, mode, valueNum);
          show(bu, 'Saved', true);
        } catch (e) {
          show(bu, 'Save failed', false);
        }
      }, 350);
    }

    modeEl.addEventListener('change', schedule);
    valEl.addEventListener('input', schedule);
    valEl.addEventListener('change', schedule);
  }

  document.querySelectorAll('.mh-rule-row').forEach(bindRow);
})();
</script>
<script>
(() => {
  const list = document.querySelector('.mh-list');
  const panelsWrap = document.getElementById('mhBenefactorPanels');
  if (!list || !panelsWrap) return;

  function select(bu, push) {
    if (!bu) return;
    list.querySelectorAll('a[data-bu]').forEach((a) => {
      const u = a.getAttribute('data-bu') || '';
      if (u && u.toLowerCase() === bu.toLowerCase()) a.classList.add('active'); else a.classList.remove('active');
    });
    panelsWrap.querySelectorAll('.mh-benefactor-panel[data-bu]').forEach((p) => {
      const u = p.getAttribute('data-bu') || '';
      p.style.display = (u && u.toLowerCase() === bu.toLowerCase()) ? '' : 'none';
    });
    if (push === true) {
      const url = new URL(window.location.href);
      url.searchParams.set('benefactor', bu);
      history.pushState({ benefactor: bu }, '', url.toString());
    }
  }

  list.querySelectorAll('a[data-bu]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const bu = a.getAttribute('data-bu') || '';
      if (!bu) return;
      e.preventDefault();
      select(bu, true);
    });
  });

  window.addEventListener('popstate', () => {
    const url = new URL(window.location.href);
    const bu = url.searchParams.get('benefactor') || '';
    if (bu) select(bu, false);
  });
})();
</script>
<script>
(() => {
  const csrf = <?php echo json_encode($csrf); ?>;
  if (!csrf) return;

  function b64urlToBuf(b64url) {
    const base64 = String(b64url || '').replace(/-/g, '+').replace(/_/g, '/');
    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
    const bin = atob(base64 + pad);
    const buf = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
  }

  function bufToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    const b64 = btoa(bin);
    return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
  }

  async function postForm(url, data) {
    const body = new URLSearchParams();
    Object.keys(data).forEach((k) => {
      const v = data[k];
      if (v === undefined || v === null) return;
      if (typeof v === 'object') body.set(k, JSON.stringify(v));
      else body.set(k, String(v));
    });
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body,
      credentials: 'same-origin',
    });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch (e) {}
    if (!res.ok) throw new Error(json && json.error ? json.error : 'request_failed');
    if (!json || json.ok !== true) throw new Error(json && json.error ? json.error : 'request_failed');
    return json;
  }

  const estateForm = document.getElementById('mhEstatePlanForm');
  const estateSaveStatus = document.getElementById('mhEstateSaveStatus');
  let estateT = null;
  let estateLast = '';
  async function estateAutosave() {
    if (!estateForm) return;
    const v = (name, def) => {
      const el = estateForm.querySelector('[name="' + name + '"]');
      const raw = el ? String(el.value || '') : '';
      const n = parseInt(raw, 10);
      return Number.isFinite(n) ? n : def;
    };
    const payload = {
      csrf,
      inactivity_days: v('inactivity_days', 90),
      challenge_days: v('challenge_days', 7),
      guardian_quorum: v('guardian_quorum', 2),
      bond_amount_mtk: v('bond_amount_mtk', 1000),
      tranche_count: v('tranche_count', 6),
      tranche_interval_days: v('tranche_interval_days', 30),
    };
    const key = Object.values(payload).join('|');
    if (key === estateLast) return;
    estateLast = key;
    if (estateSaveStatus) {
      estateSaveStatus.textContent = 'Saving…';
      estateSaveStatus.style.color = '#9aa';
    }
    try {
      await postForm('/hub/equity/benefactors.php?ajax=estate_plan_autosave', payload);
      if (estateSaveStatus) {
        estateSaveStatus.textContent = 'Saved';
        estateSaveStatus.style.color = 'rgba(16,185,129,0.95)';
      }
    } catch (e) {
      if (estateSaveStatus) {
        estateSaveStatus.textContent = 'Save failed';
        estateSaveStatus.style.color = 'rgba(239,68,68,0.95)';
      }
    }
  }

  if (estateForm) {
    estateForm.querySelectorAll('input[type="number"]').forEach((el) => {
      el.addEventListener('input', () => {
        if (estateT) clearTimeout(estateT);
        estateT = setTimeout(() => { estateAutosave().catch(() => {}); }, 450);
      });
      el.addEventListener('change', () => {
        if (estateT) clearTimeout(estateT);
        estateT = setTimeout(() => { estateAutosave().catch(() => {}); }, 10);
      });
    });
    setTimeout(() => { estateAutosave().catch(() => {}); }, 0);
  }

  const guardianList = document.getElementById('mhEstateGuardianList');
  const guardianPanels = document.getElementById('mhEstateGuardianPanels');
  if (guardianList && guardianPanels) {
    function selectGuardian(gu, push) {
      if (!gu) return;
      guardianList.querySelectorAll('a[data-gu]').forEach((a) => {
        const u = a.getAttribute('data-gu') || '';
        if (u && u.toLowerCase() === gu.toLowerCase()) a.classList.add('active'); else a.classList.remove('active');
      });
      guardianPanels.querySelectorAll('.mh-estate-guardian-panel[data-gu]').forEach((p) => {
        const u = p.getAttribute('data-gu') || '';
        p.style.display = (u && u.toLowerCase() === gu.toLowerCase()) ? '' : 'none';
      });
      if (push === true) {
        const url = new URL(window.location.href);
        url.searchParams.set('estate_guardian', gu);
        history.pushState({ estate_guardian: gu }, '', url.toString());
      }
    }

    guardianList.querySelectorAll('a[data-gu]').forEach((a) => {
      a.addEventListener('click', (e) => {
        const gu = a.getAttribute('data-gu') || '';
        if (!gu) return;
        e.preventDefault();
        selectGuardian(gu, true);
      });
    });

    window.addEventListener('popstate', () => {
      const url = new URL(window.location.href);
      const gu = url.searchParams.get('estate_guardian') || '';
      if (gu) selectGuardian(gu, false);
    });
  }

  async function runPasskey(purpose, claimId) {
    const start = await postForm('/hub/equity/benefactors.php?ajax=estate_passkey_start', { csrf, purpose, claim_id: claimId || 0 });
    const challengeId = start.challengeId;
    const pk = start.publicKey;
    if (!pk) throw new Error('missing_public_key');
    const options = JSON.parse(JSON.stringify(pk));
    if (options.challenge) options.challenge = b64urlToBuf(options.challenge);
    if (Array.isArray(options.allowCredentials)) {
      options.allowCredentials = options.allowCredentials.map((c) => {
        const out = Object.assign({}, c);
        if (out.id) out.id = b64urlToBuf(out.id);
        return out;
      });
    }
    const cred = await navigator.credentials.get({ publicKey: options });
    if (!cred) throw new Error('no_credential');
    const credential = {
      id: cred.id,
      type: cred.type,
      response: {
        authenticatorData: bufToB64url(cred.response.authenticatorData),
        clientDataJSON: bufToB64url(cred.response.clientDataJSON),
        signature: bufToB64url(cred.response.signature),
      },
    };
    const finish = await postForm('/hub/equity/benefactors.php?ajax=estate_passkey_finish', { csrf, challengeId, credential });
    return finish;
  }

  const checkinBtn = document.getElementById('mhEstateCheckinBtn');
  if (checkinBtn) {
    checkinBtn.addEventListener('click', async () => {
      checkinBtn.disabled = true;
      try {
        await runPasskey('checkin', 0);
        window.location.reload();
      } catch (e) {
        alert('Check in failed: ' + (e && e.message ? e.message : 'failed'));
        checkinBtn.disabled = false;
      }
    });
  }

  document.querySelectorAll('.mhEstateHaltBtn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const claimId = Number(btn.getAttribute('data-claim-id') || '0') || 0;
      if (!claimId) return;
      if (!window.confirm('Halt this dead-man claim?')) return;
      btn.disabled = true;
      try {
        await runPasskey('halt', claimId);
        window.location.reload();
      } catch (e) {
        alert('Halt failed: ' + (e && e.message ? e.message : 'failed'));
        btn.disabled = false;
      }
    });
  });
})();
</script>
</body>
</html>
