<?php
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/auth_functions.php';
require_once dirname(__DIR__, 2) . '/auth/tokenomics.php';
require_once dirname(__DIR__, 2) . '/auth/id/lib.php';
require_once dirname(__DIR__, 2) . '/auth/persona_registry.php';

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/tokens/tokens.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/tokens/tokens.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}

$username = trim((string)$_SESSION['mh_auth_user']);

function mh_tokens_csrf_get(): string
{
    $k = $_SESSION['mh_tokens_csrf'] ?? '';
    if (!is_string($k) || $k === '') {
        $k = bin2hex(random_bytes(16));
        $_SESSION['mh_tokens_csrf'] = $k;
    }
    return $k;
}

function mh_tokens_csrf_check(string $posted): bool
{
    $k = $_SESSION['mh_tokens_csrf'] ?? '';
    return is_string($k) && $k !== '' && hash_equals($k, $posted);
}

function mh_tokens_require_mosip_verified(string $username): bool
{
    return function_exists('mh_id_user_has_verified_mosip') ? mh_id_user_has_verified_mosip($username) : false;
}

function mh_tokens_lookup_user_profile(string $username): ?array
{
    $username = trim($username);
    if ($username === '') return null;
    try {
        $pdoReg = mh_persona_registry_pdo();
        if (!function_exists('mh_user_directory_get')) return null;
        $row = mh_user_directory_get($pdoReg, $username);
        if (!is_array($row)) return null;
        return [
            'id' => null,
            'username' => (string)($row['username'] ?? $username),
            'name' => (string)($row['display_name'] ?? ''),
            'real_first_name' => (string)($row['real_first_name'] ?? ''),
            'real_last_name' => (string)($row['real_last_name'] ?? ''),
            'persona_name' => (string)($row['persona_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
        ];
    } catch (Throwable) {
        return null;
    }
}

function mh_tokens_norm_name(string $s): string
{
    $s = trim(function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s));
    $s = preg_replace("/[^a-z\\-']/u", '', $s);
    return is_string($s) ? $s : '';
}

if (isset($_GET['action']) && $_GET['action'] === 'lookup_user') {
    $u = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
    $first = isset($_GET['first']) ? trim((string)$_GET['first']) : '';
    $last = isset($_GET['last']) ? trim((string)$_GET['last']) : '';
    $u = preg_replace('/[^a-zA-Z0-9_\\-\\.]+/', '', (string)$u);
    $u = is_string($u) ? trim($u) : '';

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if ($u === '' || strlen($u) < 3) {
        echo json_encode(['ok' => true, 'exists' => false, 'match' => false, 'message' => ''], JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $row = mh_tokens_lookup_user_profile($u);
        if (!$row) {
            echo json_encode(['ok' => true, 'exists' => false, 'match' => false, 'message' => 'No user found.'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $dbFirst = (string)($row['real_first_name'] ?? '');
        $dbLast = (string)($row['real_last_name'] ?? '');
        $userId = isset($row['id']) ? (int)$row['id'] : 0;

        $match = false;
        $msg = 'User found.';
        if ($first !== '' || $last !== '') {
            $nf = mh_tokens_norm_name($first);
            $nl = mh_tokens_norm_name($last);
            $df = mh_tokens_norm_name($dbFirst);
            $dl = mh_tokens_norm_name($dbLast);
            $match = ($nf !== '' && $nl !== '' && $df !== '' && $dl !== '' && $nf === $df && $nl === $dl);
            $msg = $match ? 'User found and name matches.' : 'User found but name/surname does not match.';
        }

        echo json_encode([
            'ok' => true,
            'exists' => true,
            'match' => $match,
            'user_id' => $userId > 0 ? $userId : null,
            'real_first_name' => $dbFirst !== '' ? $dbFirst : null,
            'real_last_name' => $dbLast !== '' ? $dbLast : null,
            'message' => $msg,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'exists' => false, 'match' => false, 'message' => 'Lookup unavailable.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function mh_tokens_ensure_transfer_schema(PDO $pdoTok): void
{
    mh_tokenomics_ensure_schema($pdoTok);
    $pdoTok->exec("CREATE TABLE IF NOT EXISTS mh_token_transfer_requests (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        request_id VARCHAR(64) NOT NULL,
        requester_username VARCHAR(255) NOT NULL,
        requester_user_id BIGINT NULL,
        requester_first_name VARCHAR(64) NULL,
        requester_last_name VARCHAR(64) NULL,
        payer_username VARCHAR(255) NOT NULL,
        payer_user_id BIGINT NULL,
        payer_first_name VARCHAR(64) NOT NULL,
        payer_last_name VARCHAR(64) NOT NULL,
        amount BIGINT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        decided_by VARCHAR(255) NULL,
        decision_note VARCHAR(255) NULL,
        executed_at DATETIME NULL,
        UNIQUE KEY uniq_request_id (request_id),
        KEY idx_payer_status (payer_username, status, created_at),
        KEY idx_requester_status (requester_username, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try { $pdoTok->exec("ALTER TABLE mh_token_transfer_requests ADD COLUMN requester_user_id BIGINT NULL"); } catch (Throwable) {}
    try { $pdoTok->exec("ALTER TABLE mh_token_transfer_requests ADD COLUMN requester_first_name VARCHAR(64) NULL"); } catch (Throwable) {}
    try { $pdoTok->exec("ALTER TABLE mh_token_transfer_requests ADD COLUMN requester_last_name VARCHAR(64) NULL"); } catch (Throwable) {}
    try { $pdoTok->exec("ALTER TABLE mh_token_transfer_requests ADD COLUMN payer_user_id BIGINT NULL"); } catch (Throwable) {}

    $pdoTok->exec("CREATE TABLE IF NOT EXISTS mh_token_transfer_intents (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        transfer_id VARCHAR(64) NOT NULL,
        sender_username VARCHAR(255) NOT NULL,
        sender_user_id BIGINT NULL,
        sender_first_name VARCHAR(64) NULL,
        sender_last_name VARCHAR(64) NULL,
        receiver_username VARCHAR(255) NOT NULL,
        receiver_user_id BIGINT NULL,
        receiver_first_name VARCHAR(64) NOT NULL,
        receiver_last_name VARCHAR(64) NOT NULL,
        amount BIGINT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        decided_at DATETIME NULL,
        decided_by VARCHAR(255) NULL,
        decision_note VARCHAR(255) NULL,
        executed_at DATETIME NULL,
        UNIQUE KEY uniq_transfer_id (transfer_id),
        KEY idx_receiver_status (receiver_username, status, created_at),
        KEY idx_sender_status (sender_username, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mh_tokens_insert_asset_txn(PDO $pdoTok, string $tenantId, string $username, int $assetClassId, string $direction, int $units, ?string $serviceKey, ?string $referenceId, ?array $meta): void
{
    $now = date('Y-m-d H:i:s');
    $last = $pdoTok->prepare("SELECT txn_hash FROM mh_asset_transactions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
    $last->execute([$tenantId]);
    $prevHash = (string)($last->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000');
    $payload = $prevHash . '|' . $tenantId . '|' . $username . '|' . $assetClassId . '|' . $direction . '|' . $units . '|' . ($serviceKey ?? '') . '|' . ($referenceId ?? '') . '|' . $now . '|' . bin2hex(random_bytes(8));
    $txnHash = hash('sha256', $payload);
    $metaJson = null;
    if (is_array($meta) && !empty($meta)) {
        $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
    }
    $ins = $pdoTok->prepare("INSERT INTO mh_asset_transactions (prev_hash, txn_hash, tenant_id, username, persona_id, meta_human_id, asset_class_id, direction, units, service_key, reference_id, meta_json, created_at)
        VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$prevHash, $txnHash, $tenantId, $username, $assetClassId, $direction, $units, $serviceKey, $referenceId, $metaJson, $now]);
}

function mh_tokens_transfer_utility_atomic(PDO $pdoTok, string $fromUser, string $toUser, int $amount, string $referenceId, array $meta, bool $manageTx = true): void
{
    if ($amount <= 0) {
        throw new RuntimeException('invalid_amount');
    }
    mh_tokenomics_ensure_schema($pdoTok);
    $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
    if ($utilityClassId < 1) {
        throw new RuntimeException('utility_token_not_ready');
    }

    $fromTenantId = mh_tokenomics_tenant_id($fromUser);
    $toTenantId = mh_tokenomics_tenant_id($toUser);
    mh_tokenomics_bootstrap_user_utility_balance($pdoTok, $fromTenantId, $fromUser);
    mh_tokenomics_bootstrap_user_utility_balance($pdoTok, $toTenantId, $toUser);

    if ($manageTx) {
        $pdoTok->beginTransaction();
    }
    try {
        $pdoTok->prepare("INSERT IGNORE INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, 0)")
            ->execute([$fromTenantId, $fromUser, $utilityClassId]);
        $pdoTok->prepare("INSERT IGNORE INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, 0)")
            ->execute([$toTenantId, $toUser, $utilityClassId]);

        $sel = $pdoTok->prepare("SELECT units_owned FROM mh_asset_ledger WHERE tenant_id = ? AND username = ? AND asset_class_id = ? FOR UPDATE");
        $sel->execute([$fromTenantId, $fromUser, $utilityClassId]);
        $fromBal = (int)($sel->fetchColumn() ?: 0);
        if ($fromBal < $amount) {
            throw new RuntimeException('insufficient_tokens');
        }
        $sel->execute([$toTenantId, $toUser, $utilityClassId]);
        $sel->fetchColumn();

        $pdoTok->prepare("UPDATE mh_asset_ledger SET units_owned = units_owned - ? WHERE tenant_id = ? AND username = ? AND asset_class_id = ?")
            ->execute([$amount, $fromTenantId, $fromUser, $utilityClassId]);
        $pdoTok->prepare("UPDATE mh_asset_ledger SET units_owned = units_owned + ? WHERE tenant_id = ? AND username = ? AND asset_class_id = ?")
            ->execute([$amount, $toTenantId, $toUser, $utilityClassId]);

        mh_tokens_insert_asset_txn($pdoTok, $fromTenantId, $fromUser, $utilityClassId, 'debit', $amount, 'transfer:peer', $referenceId, array_merge($meta, ['from' => $fromUser, 'to' => $toUser, 'amount' => $amount]));
        mh_tokens_insert_asset_txn($pdoTok, $toTenantId, $toUser, $utilityClassId, 'credit', $amount, 'transfer:peer', $referenceId, array_merge($meta, ['from' => $fromUser, 'to' => $toUser, 'amount' => $amount]));

        if ($manageTx) {
            $pdoTok->commit();
        }
    } catch (Throwable $e) {
        if ($manageTx && $pdoTok->inTransaction()) {
            $pdoTok->rollBack();
        }
        throw $e;
    }
}

$message = '';
$messageType = 'info';

try {
    $pdoTok = mh_tokenomics_get_tokenomics_pdo();
    mh_tokens_ensure_transfer_schema($pdoTok);
} catch (Throwable $e) {
    $pdoTok = null;
    $message = 'Token system unavailable.';
    $messageType = 'error';
}

$mosipVerified = false;
$kycStatus = 'none';
$kycMethod = '';
$kycAnyVerified = false;
try {
    if ($username !== '' && function_exists('mh_id_get_user_kyc_record')) {
        $rowKyc = mh_id_get_user_kyc_record($username);
        if (is_array($rowKyc)) {
            $kycStatus = strtolower(trim((string)($rowKyc['status'] ?? 'none')));
            $kycMethod = strtolower(trim((string)($rowKyc['method'] ?? '')));
            $kycAnyVerified = ($kycStatus === 'verified');
        }
        $mosipVerified = mh_tokens_require_mosip_verified($username);
    }
} catch (Throwable) {
    $mosipVerified = false;
    $kycStatus = 'none';
    $kycMethod = '';
    $kycAnyVerified = false;
}

if (is_string($username) && $username !== '' && session_status() === PHP_SESSION_ACTIVE && function_exists('mh_refresh_session_token_balance')) {
    mh_refresh_session_token_balance($username, 15);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdoTok instanceof PDO) {
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    $postedCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

    try {
        if (!mh_tokens_csrf_check($postedCsrf)) {
            throw new RuntimeException('Invalid request.');
        }

        if ($action === 'create_request') {
            if (!mh_tokens_require_mosip_verified($username)) {
                throw new RuntimeException('Requester must be MOSIP-verified to request MTK transfers.');
            }

            $requester = mh_tokens_lookup_user_profile($username);
            if (!$requester) {
                throw new RuntimeException('Requester profile unavailable.');
            }

            $payerUsername = isset($_POST['payer_username']) ? trim((string)$_POST['payer_username']) : '';
            $payerFirst = isset($_POST['payer_first_name']) ? trim((string)$_POST['payer_first_name']) : '';
            $payerLast = isset($_POST['payer_last_name']) ? trim((string)$_POST['payer_last_name']) : '';
            $amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;

            if ($payerUsername === '' || $payerFirst === '' || $payerLast === '' || $amount <= 0) {
                throw new RuntimeException('Please fill all fields.');
            }
            if (strcasecmp($payerUsername, $username) === 0) {
                throw new RuntimeException('You cannot request tokens from yourself.');
            }

            $payer = mh_tokens_lookup_user_profile($payerUsername);
            if (!$payer) {
                throw new RuntimeException('User not found.');
            }
            $dbFirst = mh_tokens_norm_name((string)($payer['real_first_name'] ?? ''));
            $dbLast = mh_tokens_norm_name((string)($payer['real_last_name'] ?? ''));
            $inFirst = mh_tokens_norm_name($payerFirst);
            $inLast = mh_tokens_norm_name($payerLast);
            if ($dbFirst === '' || $dbLast === '' || $dbFirst !== $inFirst || $dbLast !== $inLast) {
                throw new RuntimeException('Name + surname do not match the provided username.');
            }

            $requestId = bin2hex(random_bytes(16));
            $pdoTok->beginTransaction();
            try {
                $reqId = isset($requester['id']) ? (int)$requester['id'] : null;
                $reqFirst = isset($requester['real_first_name']) ? (string)$requester['real_first_name'] : '';
                $reqLast = isset($requester['real_last_name']) ? (string)$requester['real_last_name'] : '';
                $payId = isset($payer['id']) ? (int)$payer['id'] : null;
                $stmt = $pdoTok->prepare("INSERT INTO mh_token_transfer_requests (request_id, requester_username, requester_user_id, requester_first_name, requester_last_name, payer_username, payer_user_id, payer_first_name, payer_last_name, amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$requestId, $username, $reqId, $reqFirst !== '' ? $reqFirst : null, $reqLast !== '' ? $reqLast : null, $payerUsername, $payId, $payerFirst, $payerLast, $amount]);

                $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($username), $username, $utilityClassId, 'meta', 0, 'transfer:request', $requestId, [
                    'requester_user_id' => $reqId,
                    'requester_first_name' => $reqFirst,
                    'requester_last_name' => $reqLast,
                    'payer_user_id' => $payId,
                    'payer_username' => $payerUsername,
                    'payer_first_name' => $payerFirst,
                    'payer_last_name' => $payerLast,
                    'amount' => $amount,
                ]);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($payerUsername), $payerUsername, $utilityClassId, 'meta', 0, 'transfer:request', $requestId, [
                    'requester_user_id' => $reqId,
                    'requester_username' => $username,
                    'amount' => $amount,
                ]);
                $pdoTok->commit();
            } catch (Throwable $e) {
                $pdoTok->rollBack();
                throw $e;
            }

            $message = 'Transfer request created and sent for approval.';
            $messageType = 'success';
        } elseif ($action === 'create_transfer') {
            if (!mh_tokens_require_mosip_verified($username)) {
                throw new RuntimeException('Sender must be MOSIP-verified to transfer MTK.');
            }

            $sender = mh_tokens_lookup_user_profile($username);
            if (!$sender) {
                throw new RuntimeException('Sender profile unavailable.');
            }

            $receiverUsername = isset($_POST['receiver_username']) ? trim((string)$_POST['receiver_username']) : '';
            $receiverFirst = isset($_POST['receiver_first_name']) ? trim((string)$_POST['receiver_first_name']) : '';
            $receiverLast = isset($_POST['receiver_last_name']) ? trim((string)$_POST['receiver_last_name']) : '';
            $amount = isset($_POST['amount']) ? (int)$_POST['amount'] : 0;

            if ($receiverUsername === '' || $receiverFirst === '' || $receiverLast === '' || $amount <= 0) {
                throw new RuntimeException('Please fill all fields.');
            }
            if (strcasecmp($receiverUsername, $username) === 0) {
                throw new RuntimeException('You cannot transfer tokens to yourself.');
            }

            $receiver = mh_tokens_lookup_user_profile($receiverUsername);
            if (!$receiver) {
                throw new RuntimeException('User not found.');
            }
            $dbFirst = mh_tokens_norm_name((string)($receiver['real_first_name'] ?? ''));
            $dbLast = mh_tokens_norm_name((string)($receiver['real_last_name'] ?? ''));
            $inFirst = mh_tokens_norm_name($receiverFirst);
            $inLast = mh_tokens_norm_name($receiverLast);
            if ($dbFirst === '' || $dbLast === '' || $dbFirst !== $inFirst || $dbLast !== $inLast) {
                throw new RuntimeException('Name + surname do not match the provided username.');
            }

            $transferId = bin2hex(random_bytes(16));
            $pdoTok->beginTransaction();
            try {
                $senderId = isset($sender['id']) ? (int)$sender['id'] : null;
                $senderFirst = isset($sender['real_first_name']) ? (string)$sender['real_first_name'] : '';
                $senderLast = isset($sender['real_last_name']) ? (string)$sender['real_last_name'] : '';
                $receiverId = isset($receiver['id']) ? (int)$receiver['id'] : null;
                $stmt = $pdoTok->prepare("INSERT INTO mh_token_transfer_intents (transfer_id, sender_username, sender_user_id, sender_first_name, sender_last_name, receiver_username, receiver_user_id, receiver_first_name, receiver_last_name, amount, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$transferId, $username, $senderId, $senderFirst !== '' ? $senderFirst : null, $senderLast !== '' ? $senderLast : null, $receiverUsername, $receiverId, $receiverFirst, $receiverLast, $amount]);

                $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($username), $username, $utilityClassId, 'meta', 0, 'transfer:offer', $transferId, [
                    'sender_user_id' => $senderId,
                    'sender_first_name' => $senderFirst,
                    'sender_last_name' => $senderLast,
                    'receiver_user_id' => $receiverId,
                    'receiver_username' => $receiverUsername,
                    'receiver_first_name' => $receiverFirst,
                    'receiver_last_name' => $receiverLast,
                    'amount' => $amount,
                ]);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($receiverUsername), $receiverUsername, $utilityClassId, 'meta', 0, 'transfer:offer', $transferId, [
                    'sender_user_id' => $senderId,
                    'sender_username' => $username,
                    'amount' => $amount,
                ]);

                $pdoTok->commit();
            } catch (Throwable $e) {
                $pdoTok->rollBack();
                throw $e;
            }

            $message = 'Transfer created. Receiver must accept before tokens move.';
            $messageType = 'success';
        } elseif ($action === 'approve_request') {
            $requestId = isset($_POST['request_id']) ? trim((string)$_POST['request_id']) : '';
            if ($requestId === '') {
                throw new RuntimeException('Invalid request id.');
            }

            $pdoTok->beginTransaction();
            try {
                $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_requests WHERE request_id = ? FOR UPDATE");
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($req)) {
                    throw new RuntimeException('Request not found.');
                }
                if (strcasecmp((string)($req['payer_username'] ?? ''), $username) !== 0) {
                    throw new RuntimeException('Forbidden.');
                }
                if ((string)($req['status'] ?? '') !== 'pending') {
                    throw new RuntimeException('Request is no longer pending.');
                }

                $requester = (string)($req['requester_username'] ?? '');
                $amount = (int)($req['amount'] ?? 0);
                if ($requester === '' || $amount <= 0) {
                    throw new RuntimeException('Invalid request payload.');
                }

                $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($username), $username, $utilityClassId, 'meta', 0, 'transfer:approve', $requestId, [
                    'requester_username' => $requester,
                    'amount' => $amount,
                ]);

                mh_tokens_transfer_utility_atomic($pdoTok, $username, $requester, $amount, $requestId, ['action' => 'approved'], false);

                $stmt = $pdoTok->prepare("UPDATE mh_token_transfer_requests SET status = 'executed', decided_at = NOW(), decided_by = ?, executed_at = NOW() WHERE request_id = ? AND status = 'pending'");
                $stmt->execute([$username, $requestId]);

                $pdoTok->commit();
            } catch (Throwable $e) {
                $pdoTok->rollBack();
                throw $e;
            }

            $message = 'Transfer approved and executed.';
            $messageType = 'success';
        } elseif ($action === 'accept_transfer') {
            $transferId = isset($_POST['transfer_id']) ? trim((string)$_POST['transfer_id']) : '';
            if ($transferId === '') {
                throw new RuntimeException('Invalid transfer id.');
            }

            $pdoTok->beginTransaction();
            try {
                $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_intents WHERE transfer_id = ? FOR UPDATE");
                $stmt->execute([$transferId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new RuntimeException('Transfer not found.');
                }
                if (strcasecmp((string)($row['receiver_username'] ?? ''), $username) !== 0) {
                    throw new RuntimeException('Forbidden.');
                }
                if ((string)($row['status'] ?? '') !== 'pending') {
                    throw new RuntimeException('Transfer is no longer pending.');
                }

                $senderUsername = (string)($row['sender_username'] ?? '');
                $amount = (int)($row['amount'] ?? 0);
                if ($senderUsername === '' || $amount <= 0) {
                    throw new RuntimeException('Invalid transfer payload.');
                }

                $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($username), $username, $utilityClassId, 'meta', 0, 'transfer:accept', $transferId, [
                    'sender_username' => $senderUsername,
                    'amount' => $amount,
                ]);

                mh_tokens_transfer_utility_atomic($pdoTok, $senderUsername, $username, $amount, $transferId, ['action' => 'accepted'], false);

                $stmt = $pdoTok->prepare("UPDATE mh_token_transfer_intents SET status = 'executed', decided_at = NOW(), decided_by = ?, executed_at = NOW() WHERE transfer_id = ? AND status = 'pending'");
                $stmt->execute([$username, $transferId]);

                $pdoTok->commit();
            } catch (Throwable $e) {
                $pdoTok->rollBack();
                throw $e;
            }

            $message = 'Transfer accepted and executed.';
            $messageType = 'success';
        } elseif ($action === 'reject_transfer') {
            $transferId = isset($_POST['transfer_id']) ? trim((string)$_POST['transfer_id']) : '';
            $note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';
            if ($transferId === '') {
                throw new RuntimeException('Invalid transfer id.');
            }

            $pdoTok->beginTransaction();
            try {
                $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_intents WHERE transfer_id = ? FOR UPDATE");
                $stmt->execute([$transferId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new RuntimeException('Transfer not found.');
                }
                if (strcasecmp((string)($row['receiver_username'] ?? ''), $username) !== 0) {
                    throw new RuntimeException('Forbidden.');
                }
                if ((string)($row['status'] ?? '') !== 'pending') {
                    throw new RuntimeException('Transfer is no longer pending.');
                }

                $senderUsername = (string)($row['sender_username'] ?? '');
                $amount = (int)($row['amount'] ?? 0);
                $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($username), $username, $utilityClassId, 'meta', 0, 'transfer:reject', $transferId, [
                    'sender_username' => $senderUsername,
                    'amount' => $amount,
                    'note' => $note,
                ]);

                $stmt = $pdoTok->prepare("UPDATE mh_token_transfer_intents SET status = 'rejected', decided_at = NOW(), decided_by = ?, decision_note = ? WHERE transfer_id = ? AND status = 'pending'");
                $stmt->execute([$username, $note !== '' ? $note : null, $transferId]);

                $pdoTok->commit();
            } catch (Throwable $e) {
                $pdoTok->rollBack();
                throw $e;
            }

            $message = 'Transfer rejected.';
            $messageType = 'success';
        } elseif ($action === 'reject_request') {
            $requestId = isset($_POST['request_id']) ? trim((string)$_POST['request_id']) : '';
            $note = isset($_POST['note']) ? trim((string)$_POST['note']) : '';
            if ($requestId === '') {
                throw new RuntimeException('Invalid request id.');
            }

            $pdoTok->beginTransaction();
            try {
                $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_requests WHERE request_id = ? FOR UPDATE");
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($req)) {
                    throw new RuntimeException('Request not found.');
                }
                if (strcasecmp((string)($req['payer_username'] ?? ''), $username) !== 0) {
                    throw new RuntimeException('Forbidden.');
                }
                if ((string)($req['status'] ?? '') !== 'pending') {
                    throw new RuntimeException('Request is no longer pending.');
                }

                $requester = (string)($req['requester_username'] ?? '');
                $amount = (int)($req['amount'] ?? 0);
                $utilityClassId = mh_tokenomics_seed_utility_token($pdoTok);
                mh_tokens_insert_asset_txn($pdoTok, mh_tokenomics_tenant_id($username), $username, $utilityClassId, 'meta', 0, 'transfer:reject', $requestId, [
                    'requester_username' => $requester,
                    'amount' => $amount,
                    'note' => $note,
                ]);

                $stmt = $pdoTok->prepare("UPDATE mh_token_transfer_requests SET status = 'rejected', decided_at = NOW(), decided_by = ?, decision_note = ? WHERE request_id = ? AND status = 'pending'");
                $stmt->execute([$username, $note !== '' ? $note : null, $requestId]);
                $pdoTok->commit();
            } catch (Throwable $e) {
                $pdoTok->rollBack();
                throw $e;
            }

            $message = 'Transfer request rejected.';
            $messageType = 'success';
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }

    if ($messageType === 'success' && function_exists('mh_refresh_session_token_balance')) {
        mh_refresh_session_token_balance($username, 0);
    }
}

$csrf = mh_tokens_csrf_get();

$incoming = [];
$outgoing = [];
$ledger = [];
$tokenTransactions = [];
$incomingTransfers = [];
$outgoingTransfers = [];
$role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
$isKripz = (stripos($role, 'kripzmaster') !== false);

if ($pdoTok instanceof PDO) {
    try {
        $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_requests WHERE payer_username = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$username]);
        $incoming = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { $incoming = []; }

    try {
        $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_requests WHERE requester_username = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$username]);
        $outgoing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { $outgoing = []; }

    try {
        $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_intents WHERE receiver_username = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$username]);
        $incomingTransfers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { $incomingTransfers = []; }

    try {
        $stmt = $pdoTok->prepare("SELECT * FROM mh_token_transfer_intents WHERE sender_username = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$username]);
        $outgoingTransfers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { $outgoingTransfers = []; }

    try {
        $tenantId = mh_tokenomics_tenant_id($username);
        $stmt = $pdoTok->prepare("SELECT created_at, direction, units, service_key, reference_id, meta_json, txn_hash FROM mh_asset_transactions WHERE tenant_id = ? AND username = ? ORDER BY id DESC LIMIT 200");
        $stmt->execute([$tenantId, $username]);
        $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) { $ledger = []; }

    try {
        $classId = 0;
        $stmtC = $pdoTok->prepare("SELECT id FROM mh_asset_classes WHERE asset_key = ? LIMIT 1");
        $stmtC->execute(['utility:meta']);
        $classId = (int)$stmtC->fetchColumn();
        if ($classId > 0) {
            $tenantId = mh_tokenomics_tenant_id($username);
            try {
                $pdoTok->query("SELECT session_id FROM mh_stripe_checkout_orders LIMIT 1");
                $stmt = $pdoTok->prepare("
                    SELECT t.created_at, t.direction, t.units, t.service_key, t.reference_id, t.meta_json
                    FROM mh_asset_transactions t
                    INNER JOIN mh_stripe_checkout_orders o
                        ON o.session_id = t.reference_id
                       AND o.username = ?
                       AND o.kind = 'mtk'
                    WHERE t.tenant_id = ?
                      AND t.username = ?
                      AND t.asset_class_id = ?
                      AND t.service_key = 'onramp:stripe'
                    ORDER BY t.id DESC
                    LIMIT 200
                ");
                $stmt->execute([$username, $tenantId, $username, $classId]);
                $tokenTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $tokenTransactions = [];
            }
        }
    } catch (Throwable) { $tokenTransactions = []; }
}

$templatesPath = function_exists('getTemplatesPath') ? getTemplatesPath() : (dirname(__DIR__, 3) . '/templates');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tokens | Meta Humans</title>
    <?php include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
    <style>
        main.main-content.tokens-page { padding: 40px 0; }
        .tokens-page .tokens-container { max-width: 1100px; margin: 0 auto; padding: 0 24px; box-sizing: border-box; position: relative; }
        .tokens-page .tokens-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; align-items: stretch; }
        .tokens-page .panel { background: rgba(0, 212, 255, 0.05); border: 1px solid rgba(0, 212, 255, 0.18); border-radius: 14px; padding: 18px; min-width: 0; height: 100%; }
        .tokens-page .panel h2 { margin: 0 0 10px 0; font-family: 'Orbitron', sans-serif; font-size: 1rem; letter-spacing: 1px; color: var(--theme-primary, #00d4ff); }
        .tokens-page .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .tokens-page label { display: block; font-weight: 600; margin: 10px 0 6px; color: rgba(255,255,255,0.85); }
        .tokens-page input, .tokens-page button, .tokens-page textarea { width: 100%; box-sizing: border-box; background: rgba(0,0,0,0.25); border: 1px solid rgba(0, 212, 255, 0.25); color: #fff; border-radius: 10px; padding: 11px 12px; }
        .tokens-page textarea { min-height: 44px; resize: vertical; }
        .tokens-page button { cursor: pointer; background: var(--theme-primary, #00d4ff); color: #000; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
        .tokens-page button.secondary { background: transparent; color: var(--theme-primary, #00d4ff); border: 1px solid var(--theme-primary, #00d4ff); }
        .tokens-page .alert { border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; border: 1px solid rgba(255,255,255,0.18); }
        .tokens-page .alert.success { background: rgba(16, 185, 129, 0.12); border-color: rgba(16, 185, 129, 0.45); color: rgba(210,255,235,0.95); }
        .tokens-page .alert.error { background: rgba(255, 80, 80, 0.12); border-color: rgba(255, 80, 80, 0.55); color: rgba(255,220,220,0.95); }
        .tokens-page .hint { color: rgba(255,255,255,0.65); font-size: 0.9rem; margin-top: 6px; }
        .tokens-page table { width: 100%; border-collapse: collapse; margin-top: 10px; table-layout: fixed; }
        .tokens-page th, .tokens-page td { padding: 10px; border-bottom: 1px solid rgba(255,255,255,0.08); text-align: left; vertical-align: top; overflow-wrap: anywhere; word-break: break-word; }
        .tokens-page th { font-family: 'Orbitron', sans-serif; font-size: 0.8rem; color: rgba(255,255,255,0.75); }
        .tokens-page code { color: rgba(255,255,255,0.9); white-space: normal; word-break: break-all; display: block; }
        .tokens-page .recon-row { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .tokens-page .recon-row input { min-width: 220px; width: auto; }
        .tokens-page .recon-row button { width: auto; padding: 10px 12px; }
        .tokens-page .txn-muted { color: rgba(255,255,255,0.65); font-size: 0.95rem; }
        .tokens-page .txn-table th { color: rgba(255,255,255,0.75); }
        .tokens-page .lookup-status { margin-top: 8px; font-size: 0.9rem; color: rgba(255,255,255,0.7); }
        .tokens-page .lookup-status.ok { color: rgba(120,255,180,.95); }
        .tokens-page .lookup-status.bad { color: rgba(255,180,180,.95); }
        .tokens-page .table-wrap { max-height: 320px; overflow: auto; border-radius: 10px; border: 1px solid rgba(255,255,255,0.06); }
        .tokens-page .table-wrap table { margin-top: 0; }
        .tokens-page .dt-controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin: 10px 0 10px; }
        .tokens-page .dt-controls input[type="search"] { width: 240px; padding: 10px 12px; }
        .tokens-page .dt-controls select { width: auto; padding: 10px 12px; }
        .tokens-page .dt-controls .dt-meta { color: rgba(255,255,255,0.65); font-size: 0.9rem; margin-left: auto; }
        .tokens-page .dt-controls .dt-pager { display: flex; gap: 8px; align-items: center; }
        .tokens-page .dt-controls .dt-pager button { width: auto; padding: 10px 12px; }
        .tokens-page .dt-controls .dt-pager .dt-page { color: rgba(255,255,255,0.65); font-size: 0.9rem; }
        @media (max-width: 900px) {
            .tokens-page .tokens-grid { grid-template-columns: 1fr; }
        }
        .mh-verify-modal { display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.72); z-index: 10000; padding: 18px; align-items: center; justify-content: center; }
        .mh-verify-card { max-width: 640px; width: 100%; background: rgba(20,20,25,0.96); border: 1px solid rgba(0,212,255,0.25); border-radius: 16px; padding: 16px; }
        .mh-verify-title { margin: 0 0 10px 0; font-family: 'Orbitron', sans-serif; letter-spacing: 1px; color: var(--theme-primary, #00d4ff); font-weight: 800; }
        .mh-verify-text { color: rgba(255,255,255,0.88); line-height: 1.35; }
        .mh-verify-actions { display:flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .mh-verify-actions button { width: auto; padding: 10px 14px; }
        .mh-verify-note { margin-top: 10px; color: rgba(255,255,255,0.7); font-size: 0.9rem; }
        .mh-verify-top { position: absolute; top: 0; right: 24px; display: inline-flex; gap: 10px; align-items: center; padding-top: 2px; }
        .mh-verify-badge { display:inline-flex; align-items:center; gap:8px; border-radius: 999px; padding: 6px 10px; border: 1px solid rgba(255,255,255,0.14); font-weight: 800; font-size: 12px; letter-spacing: 1px; font-family: 'Orbitron', sans-serif; }
        .mh-verify-badge.ok { background: rgba(16,185,129,0.14); border-color: rgba(16,185,129,0.35); color:#c8ffe8; }
        .mh-verify-badge.bad { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.35); color:#ffd0d0; }
        .mh-verify-btn { display:inline-block; text-decoration:none; border-radius: 999px; padding: 8px 12px; border: 1px solid var(--theme-primary, #00d4ff); color: var(--theme-primary, #00d4ff); font-weight: 900; letter-spacing: 1px; font-family: 'Orbitron', sans-serif; background: transparent; }
        @media (max-width: 600px) {
            .mh-verify-top { position: static; margin-top: 10px; justify-content: flex-start; right: auto; padding-top: 0; }
        }
    </style>
</head>
<body class="hub-page">
<?php include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content tokens-page">
    <div class="tokens-container">
        <div class="mh-verify-top">
            <div class="mh-verify-badge <?php echo $kycAnyVerified ? 'ok' : 'bad'; ?>">
                <?php echo $kycAnyVerified ? 'KYC VERIFIED' : 'KYC NOT VERIFIED'; ?>
            </div>
            <div class="mh-verify-badge <?php echo $mosipVerified ? 'ok' : 'bad'; ?>">
                <?php echo $mosipVerified ? 'MOSIP VERIFIED' : 'MOSIP REQUIRED'; ?>
            </div>
            <?php if (!$mosipVerified): ?><a class="mh-verify-btn" href="/auth/id/index.php">Verify here</a><?php endif; ?>
        </div>
        <h1 style="margin: 0 0 8px 0; font-family: 'Orbitron', sans-serif; letter-spacing: 1px;">TOKENS</h1>
        <div class="hint">Balance: <b><?php echo number_format((int)($_SESSION['tokens'] ?? 0)); ?> MTK</b></div>
        <div class="hint"><a href="/auth/id/index.php" style="color: var(--theme-primary, #00d4ff); text-decoration: none; font-weight: 700;">Verification Center</a></div>

        <?php if ($message !== ''): ?>
            <div class="alert <?php echo $messageType === 'success' ? 'success' : ($messageType === 'error' ? 'error' : ''); ?>">
                <?php echo htmlspecialchars($message, ENT_QUOTES); ?>
            </div>
        <?php endif; ?>
        <div id="mhClientAlert" class="alert error" style="display:none;"></div>

        <div id="mhVerifyModal" class="mh-verify-modal">
            <div class="mh-verify-card">
                <div class="mh-verify-title">Verification Required</div>
                <div class="mh-verify-text">
                    Your account is not MOSIP-verified and this request cannot proceed without MOSIP / ID / face verification.
                    <div style="margin-top: 10px; font-weight: 700;">Can I transfer you to the verification page?</div>
                </div>
                <div class="mh-verify-actions">
                    <button type="button" id="mhVerifyYes">Yes</button>
                    <button type="button" class="secondary" id="mhVerifyNo">No</button>
                </div>
                <div class="mh-verify-note">After successful verification, you will be transferred back to this page to complete your request/transfer.</div>
            </div>
        </div>

        <div class="tokens-grid">
            <div class="panel">
                <h2>Request MTK Transfer</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="create_request">
                    <label>Payer Username</label>
                    <input type="text" id="payerUsername" name="payer_username" placeholder="username" required>
                    <div id="payerLookupStatus" class="lookup-status"></div>
                    <div class="row">
                        <div>
                            <label>Payer First Name</label>
                            <input type="text" id="payerFirst" name="payer_first_name" placeholder="name" required>
                        </div>
                        <div>
                            <label>Payer Surname</label>
                            <input type="text" id="payerLast" name="payer_last_name" placeholder="surname" required>
                        </div>
                    </div>
                    <label>Amount (MTK)</label>
                    <input type="number" name="amount" min="1" step="1" required>
                    <div class="hint">Request must be approved by the payer before tokens are transferred.</div>
                    <button type="submit">Send Request</button>
                </form>
            </div>

            <div class="panel">
                <h2>Send MTK Transfer</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="create_transfer">
                    <label>Receiver Username</label>
                    <input type="text" id="receiverUsername" name="receiver_username" placeholder="username" required>
                    <div id="receiverLookupStatus" class="lookup-status"></div>
                    <div class="row">
                        <div>
                            <label>Receiver First Name</label>
                            <input type="text" id="receiverFirst" name="receiver_first_name" placeholder="name" required>
                        </div>
                        <div>
                            <label>Receiver Surname</label>
                            <input type="text" id="receiverLast" name="receiver_last_name" placeholder="surname" required>
                        </div>
                    </div>
                    <label>Amount (MTK)</label>
                    <input type="number" name="amount" min="1" step="1" required>
                    <div class="hint">Receiver must accept before tokens move.</div>
                    <button type="submit">Create Transfer</button>
                </form>
            </div>

            <div class="panel">
                <h2>Incoming Requests (You Pay)</h2>
                <div class="table-wrap">
                <table data-dt="incoming-requests" data-dt-default="10">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incoming)): ?>
                            <tr><td colspan="4" style="color: rgba(255,255,255,0.65);">No requests.</td></tr>
                        <?php else: foreach ($incoming as $r): ?>
                            <?php
                                $rid = (string)($r['request_id'] ?? '');
                                $from = (string)($r['requester_username'] ?? '');
                                $amt = (int)($r['amount'] ?? 0);
                                $st = (string)($r['status'] ?? '');
                            ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($from, ENT_QUOTES); ?>
                                    <?php
                                        $fromId = isset($r['requester_user_id']) ? (string)$r['requester_user_id'] : '';
                                        $fromFirst = isset($r['requester_first_name']) ? (string)$r['requester_first_name'] : '';
                                        $fromLast = isset($r['requester_last_name']) ? (string)$r['requester_last_name'] : '';
                                        $fromLine = trim(($fromId !== '' ? ('ID ' . $fromId) : '') . ' ' . ($fromFirst !== '' || $fromLast !== '' ? ($fromFirst . ' ' . $fromLast) : ''));
                                    ?>
                                    <?php if ($fromLine !== ''): ?>
                                        <div class="hint"><?php echo htmlspecialchars($fromLine, ENT_QUOTES); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($amt); ?> MTK</td>
                                <td><?php echo htmlspecialchars($st, ENT_QUOTES); ?></td>
                                <td>
                                    <?php if ($st === 'pending' && $rid !== ''): ?>
                                        <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($rid, ENT_QUOTES); ?>">
                                            <input type="hidden" name="action" value="approve_request">
                                            <button type="submit" style="width:auto; padding:10px 12px;">Approve</button>
                                        </form>
                                        <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($rid, ENT_QUOTES); ?>">
                                            <input type="hidden" name="action" value="reject_request">
                                            <textarea name="note" placeholder="Optional note" style="width: 220px; padding: 10px 12px;"></textarea>
                                            <button type="submit" class="secondary" style="width:auto; padding:10px 12px;">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: rgba(255,255,255,0.65);">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="panel">
                <h2>MTK Purchases</h2>
                <div class="recon-row" style="margin-bottom: 10px;">
                    <button type="button" class="secondary" onclick="mhRefreshMyOrders()">Refresh Status</button>
                    <button type="button" onclick="mhReconcileMyOrders()">Retry Credit</button>
                    <button type="button" class="secondary" id="mhBuyTokensOpen">Buy Tokens</button>
                </div>
                <div id="mhMyOrdersStatus" class="hint"></div>
                <div id="mhMyOrdersWrap" class="table-wrap" style="margin-top: 10px; display:none;">
                    <table class="txn-table">
                        <thead>
                            <tr>
                                <th>Created</th>
                                <th>USD</th>
                                <th>Expected</th>
                                <th>Status</th>
                                <th>Flag</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="mhMyOrdersBody"></tbody>
                    </table>
                </div>
                <?php if ($isKripz): ?>
                    <div class="recon-row">
                        <input id="reconUsername" type="text" value="<?php echo htmlspecialchars($username, ENT_QUOTES); ?>" />
                        <button type="button" onclick="reconcileStripe()">Reconcile Purchases</button>
                    </div>
                    <div id="reconStatus" class="hint"></div>
                <?php endif; ?>
                <div style="margin-top: 16px;">
                    <div class="hint"><b>Recent Purchases</b></div>
                    <div class="table-wrap">
                    <table class="txn-table" data-dt="mtk-purchases" data-dt-default="10">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>USD</th>
                                <th>Tokens</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tokenTransactions)): ?>
                                <tr><td colspan="4" style="color: rgba(255,255,255,0.65);">No purchases recorded yet.</td></tr>
                            <?php else: foreach ($tokenTransactions as $tx): ?>
                                <?php
                                    $meta = isset($tx['meta_json']) && is_string($tx['meta_json']) ? json_decode((string)$tx['meta_json'], true) : null;
                                    $meta = is_array($meta) ? $meta : [];
                                    $usd = isset($meta['amount_usd']) ? (string)$meta['amount_usd'] : '';
                                    $status = isset($tx['direction']) ? (string)$tx['direction'] : '';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($tx['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                    <td><?php echo htmlspecialchars($usd, ENT_QUOTES); ?></td>
                                    <td><?php echo number_format((int)($tx['units'] ?? 0)); ?></td>
                                    <td><?php echo htmlspecialchars($status, ENT_QUOTES); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <div id="mhBuyTokensModal" style="display:none; position:fixed; inset:0; background: rgba(0,0,0,0.72); z-index: 9999; padding: 18px; align-items:center; justify-content:center;">
                <div style="max-width: 1000px; width: 100%; background: rgba(20,20,25,0.96); border: 1px solid rgba(0,212,255,0.25); border-radius: 16px; padding: 14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap: 12px;">
                        <div style="font-family:'Orbitron',sans-serif; color: var(--primary,#00d4ff); font-weight:800;">Buy MTK Tokens</div>
                        <button type="button" class="secondary" id="mhBuyTokensClose" style="width:auto; padding:10px 12px;">Close</button>
                    </div>
                    <div style="margin-top: 12px;">
                        <iframe id="mhBuyTokensFrame" src="/hub/genesis/tokenization.php" style="width:100%; height: 74vh; border: 0; border-radius: 12px; background: rgba(0,0,0,0.35);"></iframe>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>Incoming Transfers (You Receive)</h2>
                <div class="table-wrap">
                <table data-dt="incoming-transfers" data-dt-default="10">
                    <thead>
                        <tr>
                            <th>From</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incomingTransfers)): ?>
                            <tr><td colspan="4" style="color: rgba(255,255,255,0.65);">No transfers.</td></tr>
                        <?php else: foreach ($incomingTransfers as $t): ?>
                            <?php
                                $tid = (string)($t['transfer_id'] ?? '');
                                $from = (string)($t['sender_username'] ?? '');
                                $amt = (int)($t['amount'] ?? 0);
                                $st = (string)($t['status'] ?? '');
                                $fromId = isset($t['sender_user_id']) ? (string)$t['sender_user_id'] : '';
                                $fromFirst = isset($t['sender_first_name']) ? (string)$t['sender_first_name'] : '';
                                $fromLast = isset($t['sender_last_name']) ? (string)$t['sender_last_name'] : '';
                                $fromLine = trim(($fromId !== '' ? ('ID ' . $fromId) : '') . ' ' . ($fromFirst !== '' || $fromLast !== '' ? ($fromFirst . ' ' . $fromLast) : ''));
                            ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($from, ENT_QUOTES); ?>
                                    <?php if ($fromLine !== ''): ?>
                                        <div class="hint"><?php echo htmlspecialchars($fromLine, ENT_QUOTES); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($amt); ?> MTK</td>
                                <td><?php echo htmlspecialchars($st, ENT_QUOTES); ?></td>
                                <td>
                                    <?php if ($st === 'pending' && $tid !== ''): ?>
                                        <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap;">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="transfer_id" value="<?php echo htmlspecialchars($tid, ENT_QUOTES); ?>">
                                            <input type="hidden" name="action" value="accept_transfer">
                                            <button type="submit" style="width:auto; padding:10px 12px;">Accept</button>
                                        </form>
                                        <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                            <input type="hidden" name="transfer_id" value="<?php echo htmlspecialchars($tid, ENT_QUOTES); ?>">
                                            <input type="hidden" name="action" value="reject_transfer">
                                            <textarea name="note" placeholder="Optional note" style="width: 220px; padding: 10px 12px;"></textarea>
                                            <button type="submit" class="secondary" style="width:auto; padding:10px 12px;">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: rgba(255,255,255,0.65);">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="panel">
                <h2>Outgoing Requests (You Receive)</h2>
                <div class="table-wrap">
                <table data-dt="outgoing-requests" data-dt-default="10">
                    <thead>
                        <tr>
                            <th>To</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outgoing)): ?>
                            <tr><td colspan="4" style="color: rgba(255,255,255,0.65);">No requests.</td></tr>
                        <?php else: foreach ($outgoing as $r): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars((string)($r['payer_username'] ?? ''), ENT_QUOTES); ?>
                                    <?php
                                        $toId = isset($r['payer_user_id']) ? (string)$r['payer_user_id'] : '';
                                        $toLine = trim(($toId !== '' ? ('ID ' . $toId) : '') . ' ' . (string)($r['payer_first_name'] ?? '') . ' ' . (string)($r['payer_last_name'] ?? ''));
                                    ?>
                                    <?php if ($toLine !== ''): ?>
                                        <div class="hint"><?php echo htmlspecialchars(trim($toLine), ENT_QUOTES); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((int)($r['amount'] ?? 0)); ?> MTK</td>
                                <td><?php echo htmlspecialchars((string)($r['status'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="panel">
                <h2>Outgoing Transfers (You Send)</h2>
                <div class="table-wrap">
                <table data-dt="outgoing-transfers" data-dt-default="10">
                    <thead>
                        <tr>
                            <th>To</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outgoingTransfers)): ?>
                            <tr><td colspan="4" style="color: rgba(255,255,255,0.65);">No transfers.</td></tr>
                        <?php else: foreach ($outgoingTransfers as $t): ?>
                            <?php
                                $to = (string)($t['receiver_username'] ?? '');
                                $amt = (int)($t['amount'] ?? 0);
                                $st = (string)($t['status'] ?? '');
                                $toId = isset($t['receiver_user_id']) ? (string)$t['receiver_user_id'] : '';
                                $toLine = trim(($toId !== '' ? ('ID ' . $toId) : '') . ' ' . (string)($t['receiver_first_name'] ?? '') . ' ' . (string)($t['receiver_last_name'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($to, ENT_QUOTES); ?>
                                    <?php if ($toLine !== ''): ?>
                                        <div class="hint"><?php echo htmlspecialchars(trim($toLine), ENT_QUOTES); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($amt); ?> MTK</td>
                                <td><?php echo htmlspecialchars($st, ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)($t['created_at'] ?? ''), ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="panel">
                <h2>Immutable Ledger</h2>
                <div class="table-wrap">
                <table data-dt="immutable-ledger" data-dt-default="10">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Units</th>
                            <th>Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger)): ?>
                            <tr><td colspan="4" style="color: rgba(255,255,255,0.65);">No ledger entries.</td></tr>
                        <?php else: foreach ($ledger as $e): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($e['created_at'] ?? ''), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)($e['service_key'] ?? ($e['direction'] ?? '')), ENT_QUOTES); ?></td>
                                <td><?php echo htmlspecialchars((string)($e['direction'] ?? ''), ENT_QUOTES); ?> <?php echo number_format((int)($e['units'] ?? 0)); ?></td>
                                <td><code><?php echo htmlspecialchars((string)($e['reference_id'] ?? ''), ENT_QUOTES); ?></code></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
                <div class="hint">Entries are hash-chained in <code>mh_asset_transactions</code> via <code>prev_hash</code> and <code>txn_hash</code>.</div>
            </div>
        </div>
    </div>
</main>
<?php include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
<?php if ($isKripz): ?>
<script>
function reconcileStripe() {
    const el = document.getElementById('reconUsername');
    const statusEl = document.getElementById('reconStatus');
    const username = (el && el.value) ? el.value.trim() : '';
    if (!username) {
        if (statusEl) statusEl.textContent = 'Enter a username to reconcile.';
        return;
    }
    if (statusEl) statusEl.textContent = 'Reconciling...';
    fetch('/hub/genesis/action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=reconcile_stripe&username=' + encodeURIComponent(username)
    }).then(r => r.json()).then(d => {
        if (d && d.success) {
            if (statusEl) statusEl.textContent = 'Matched ' + (d.matched || 0) + ' paid sessions. Credited ' + (d.credited || 0) + '. Reloading...';
            setTimeout(() => window.location.reload(), 1200);
        } else {
            if (statusEl) statusEl.textContent = 'Reconcile failed: ' + ((d && d.error) ? d.error : 'Unknown error');
        }
    }).catch(e => {
        if (statusEl) statusEl.textContent = 'Reconcile failed: ' + (e && e.message ? e.message : 'Network error');
    });
}
</script>
<script>
(() => {
  const openBtn = document.getElementById('mhBuyTokensOpen');
  const modal = document.getElementById('mhBuyTokensModal');
  const closeBtn = document.getElementById('mhBuyTokensClose');
  const frame = document.getElementById('mhBuyTokensFrame');
  if (!openBtn || !modal || !closeBtn) return;
  function open() {
    modal.style.display = 'flex';
    if (frame && (!frame.getAttribute('src') || frame.getAttribute('src') === '')) {
      frame.setAttribute('src', '/hub/genesis/tokenization.php');
    }
  }
  function close() { modal.style.display = 'none'; }
  openBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
})();
</script>
<?php endif; ?>
<script>
function mhSetMyOrdersStatus(kind, text) {
    const el = document.getElementById('mhMyOrdersStatus');
    if (!el) return;
    el.classList.remove('ok');
    el.classList.remove('bad');
    if (kind === 'ok') el.classList.add('ok');
    if (kind === 'bad') el.classList.add('bad');
    el.textContent = text || '';
}

function mhRenderMyOrders(orders) {
    const wrap = document.getElementById('mhMyOrdersWrap');
    const body = document.getElementById('mhMyOrdersBody');
    if (!wrap || !body) return;
    body.innerHTML = '';
    if (!Array.isArray(orders) || orders.length === 0) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = 'block';
    for (const o of orders) {
        const createdAt = String((o && o.created_at) ? o.created_at : '');
        const usd = String((o && o.amount_usd) ? o.amount_usd : '');
        const expected = String((o && o.tokens_expected) ? o.tokens_expected : '');
        const st = String((o && o.status) ? o.status : '');
        const flagged = (o && (o.flagged === 1 || o.flagged === '1' || o.flagged === true));
        const flagReason = String((o && o.flag_reason) ? o.flag_reason : '');
        const lastError = String((o && o.last_error) ? o.last_error : '');
        const sid = String((o && o.session_id) ? o.session_id : '');

        const tr = document.createElement('tr');
        const tdCreated = document.createElement('td');
        tdCreated.textContent = createdAt;
        const tdUsd = document.createElement('td');
        tdUsd.textContent = usd;
        const tdExp = document.createElement('td');
        tdExp.textContent = expected ? Number(expected).toLocaleString() : '';
        const tdSt = document.createElement('td');
        tdSt.textContent = st;
        const tdFlag = document.createElement('td');
        tdFlag.textContent = flagged ? (flagReason || 'flagged') : '';
        const tdAct = document.createElement('td');

        if (st !== 'credited' && sid) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Verify';
            btn.style.width = 'auto';
            btn.style.padding = '10px 12px';
            btn.onclick = async function() {
                mhSetMyOrdersStatus('ok', 'Verifying payment...');
                try {
                    const resp = await fetch('/hub/genesis/action.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=verify_my_order&session_id=' + encodeURIComponent(sid),
                    });
                    const d = await resp.json();
                    if (d && d.success) {
                        mhSetMyOrdersStatus('ok', (d.flagged ? 'Payment verified and credited (flagged).' : 'Payment verified and credited.') + ' Reloading...');
                        setTimeout(() => window.location.reload(), 1200);
                        return;
                    }
                    const err = (d && (d.error || d.message)) ? String(d.error || d.message) : 'verify_failed';
                    mhSetMyOrdersStatus('bad', 'Verify failed: ' + err);
                    mhRefreshMyOrders();
                } catch (e) {
                    mhSetMyOrdersStatus('bad', 'Verify failed: ' + (e && e.message ? e.message : 'network_error'));
                }
            };
            tdAct.appendChild(btn);
        } else {
            const span = document.createElement('span');
            span.style.color = 'rgba(255,255,255,0.65)';
            span.textContent = lastError ? lastError : '—';
            tdAct.appendChild(span);
        }

        tr.appendChild(tdCreated);
        tr.appendChild(tdUsd);
        tr.appendChild(tdExp);
        tr.appendChild(tdSt);
        tr.appendChild(tdFlag);
        tr.appendChild(tdAct);
        body.appendChild(tr);
    }
}

async function mhRefreshMyOrders() {
    mhSetMyOrdersStatus('ok', 'Loading purchase status...');
    try {
        const resp = await fetch('/hub/genesis/action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=my_mtk_orders',
        });
        const d = await resp.json();
        if (d && d.success) {
            const orders = Array.isArray(d.orders) ? d.orders : [];
            mhRenderMyOrders(orders);
            const pending = orders.filter(o => o && String(o.status || '') !== 'credited').length;
            if (orders.length === 0) mhSetMyOrdersStatus('', '');
            else if (pending > 0) mhSetMyOrdersStatus('bad', 'You have ' + pending + ' purchase(s) not credited yet. Use Verify/Retry Credit.');
            else mhSetMyOrdersStatus('ok', 'All recent purchases are credited.');
            return;
        }
        mhSetMyOrdersStatus('bad', 'Status unavailable.');
    } catch (e) {
        mhSetMyOrdersStatus('bad', 'Status unavailable: ' + (e && e.message ? e.message : 'network_error'));
    }
}

async function mhReconcileMyOrders() {
    mhSetMyOrdersStatus('ok', 'Retrying credit for pending purchases...');
    try {
        const resp = await fetch('/hub/genesis/action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=reconcile_my_orders',
        });
        const d = await resp.json();
        if (d && d.success) {
            mhSetMyOrdersStatus('ok', 'Checked ' + (d.checked || 0) + ' · Credited ' + (d.credited || 0) + (d.failed ? (' · Failed ' + d.failed) : '') + '. Reloading...');
            setTimeout(() => window.location.reload(), 1200);
            return;
        }
        mhSetMyOrdersStatus('bad', 'Retry failed.');
        mhRefreshMyOrders();
    } catch (e) {
        mhSetMyOrdersStatus('bad', 'Retry failed: ' + (e && e.message ? e.message : 'network_error'));
    }
}

document.addEventListener('DOMContentLoaded', function() {
    mhRefreshMyOrders();
});
</script>
<script>
(function() {
    function debounce(fn, wait) {
        let t = null;
        return function() {
            const args = arguments;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(null, args); }, wait);
        };
    }

    function setStatus(el, kind, text) {
        if (!el) return;
        el.classList.remove('ok');
        el.classList.remove('bad');
        if (kind === 'ok') el.classList.add('ok');
        if (kind === 'bad') el.classList.add('bad');
        el.textContent = text || '';
    }

    const payerU = document.getElementById('payerUsername');
    const payerF = document.getElementById('payerFirst');
    const payerL = document.getElementById('payerLast');
    const payerS = document.getElementById('payerLookupStatus');

    const recvU = document.getElementById('receiverUsername');
    const recvF = document.getElementById('receiverFirst');
    const recvL = document.getElementById('receiverLast');
    const recvS = document.getElementById('receiverLookupStatus');

    const doLookup = debounce(function(username, first, last, statusEl) {
        username = (username || '').trim();
        first = (first || '').trim();
        last = (last || '').trim();
        if (username.length < 3) {
            setStatus(statusEl, '', '');
            return;
        }
        const url = '/hub/tokens/tokens.php?action=lookup_user&username=' + encodeURIComponent(username) + '&first=' + encodeURIComponent(first) + '&last=' + encodeURIComponent(last);
        fetch(url, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d || d.ok !== true) {
                    setStatus(statusEl, 'bad', 'Lookup unavailable.');
                    return;
                }
                if (!d.exists) {
                    setStatus(statusEl, 'bad', 'No user found.');
                    return;
                }
                const idPart = d.user_id ? ('ID ' + d.user_id + ' · ') : '';
                const namePart = (d.real_first_name && d.real_last_name) ? (d.real_first_name + ' ' + d.real_last_name) : '';
                const base = idPart + (namePart ? namePart : 'User found');
                if (first !== '' || last !== '') {
                    setStatus(statusEl, d.match ? 'ok' : 'bad', base + (d.match ? ' · Match' : ' · Name mismatch'));
                } else {
                    setStatus(statusEl, 'ok', base);
                }
            })
            .catch(() => setStatus(statusEl, 'bad', 'Lookup unavailable.'));
    }, 350);

    function wire(u, f, l, s) {
        if (!u || !s) return;
        const handler = function() { doLookup(u.value, f ? f.value : '', l ? l.value : '', s); };
        u.addEventListener('input', handler);
        if (f) f.addEventListener('input', handler);
        if (l) l.addEventListener('input', handler);
        u.addEventListener('blur', handler);
        if (f) f.addEventListener('blur', handler);
        if (l) l.addEventListener('blur', handler);
    }

    wire(payerU, payerF, payerL, payerS);
    wire(recvU, recvF, recvL, recvS);
})();
</script>
<script>
(function() {
    function buildControls(table) {
        const panel = table.closest('.panel');
        if (!panel) return null;
        const wrap = table.parentElement && table.parentElement.classList.contains('table-wrap') ? table.parentElement : null;

        const controls = document.createElement('div');
        controls.className = 'dt-controls';

        const search = document.createElement('input');
        search.type = 'search';
        search.placeholder = 'Search...';

        const select = document.createElement('select');
        const opts = [
            { v: '5', t: 'Show 5' },
            { v: '10', t: 'Show 10' },
            { v: '50', t: 'Show 50' },
            { v: '100', t: 'Show 100' },
            { v: 'all', t: 'Show all' },
        ];
        opts.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.v;
            opt.textContent = o.t;
            select.appendChild(opt);
        });

        const pager = document.createElement('div');
        pager.className = 'dt-pager';
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.className = 'secondary';
        prev.textContent = 'Prev';
        const pageText = document.createElement('span');
        pageText.className = 'dt-page';
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'secondary';
        next.textContent = 'Next';
        pager.appendChild(prev);
        pager.appendChild(pageText);
        pager.appendChild(next);

        const meta = document.createElement('div');
        meta.className = 'dt-meta';

        controls.appendChild(search);
        controls.appendChild(select);
        controls.appendChild(pager);
        controls.appendChild(meta);

        if (wrap && wrap.parentElement) {
            wrap.parentElement.insertBefore(controls, wrap);
        } else {
            panel.insertBefore(controls, table);
        }

        return { controls, search, select, prev, next, pageText, meta };
    }

    function enhanceTable(table) {
        const tbody = table.tBodies && table.tBodies.length ? table.tBodies[0] : null;
        if (!tbody) return;

        const ui = buildControls(table);
        if (!ui) return;

        const rawRows = Array.from(tbody.querySelectorAll('tr'));
        const placeholderRows = rawRows.filter(r => {
            const tds = r.querySelectorAll('td');
            return tds.length === 1 && tds[0].hasAttribute('colspan');
        });
        const dataRows = rawRows.filter(r => !placeholderRows.includes(r));
        placeholderRows.forEach(r => r.style.display = 'none');

        const emptyRow = document.createElement('tr');
        emptyRow.setAttribute('data-dt-empty', '1');
        const emptyTd = document.createElement('td');
        emptyTd.colSpan = Math.max(1, table.querySelectorAll('thead th').length);
        emptyTd.style.color = 'rgba(255,255,255,0.65)';
        emptyTd.textContent = 'No results.';
        emptyRow.appendChild(emptyTd);
        tbody.appendChild(emptyRow);

        let page = 1;
        let query = '';
        let pageSize = (table.getAttribute('data-dt-default') || '10').toLowerCase();
        if (!['5','10','50','100','all'].includes(pageSize)) pageSize = '10';
        ui.select.value = pageSize;

        function render() {
            query = (ui.search.value || '').trim().toLowerCase();
            const filtered = query === '' ? dataRows : dataRows.filter(r => (r.textContent || '').toLowerCase().includes(query));
            const total = filtered.length;
            const size = ui.select.value;

            let totalPages = 1;
            if (size !== 'all') {
                const n = parseInt(size, 10);
                totalPages = n > 0 ? Math.max(1, Math.ceil(total / n)) : 1;
            }
            if (page > totalPages) page = totalPages;
            if (page < 1) page = 1;

            dataRows.forEach(r => r.style.display = 'none');
            emptyRow.style.display = 'none';

            if (total === 0) {
                emptyRow.style.display = '';
                ui.pageText.textContent = 'Page 0/0';
                ui.meta.textContent = query ? '0 matches' : '0 rows';
                ui.prev.disabled = true;
                ui.next.disabled = true;
                return;
            }

            let start = 0;
            let end = total;
            if (size !== 'all') {
                const n = parseInt(size, 10);
                start = (page - 1) * n;
                end = Math.min(total, start + n);
            }

            for (let i = start; i < end; i++) {
                filtered[i].style.display = '';
            }

            ui.pageText.textContent = (size === 'all') ? 'All' : ('Page ' + page + '/' + totalPages);
            ui.meta.textContent = (query ? (total + ' matches') : (total + ' rows')) + (size === 'all' ? '' : (' · showing ' + (end - start)));
            ui.prev.disabled = (size === 'all') || (page <= 1);
            ui.next.disabled = (size === 'all') || (page >= totalPages);
        }

        ui.search.addEventListener('input', function() { page = 1; render(); });
        ui.select.addEventListener('change', function() { page = 1; render(); });
        ui.prev.addEventListener('click', function() { page -= 1; render(); });
        ui.next.addEventListener('click', function() { page += 1; render(); });

        render();
    }

    document.querySelectorAll('table[data-dt]').forEach(enhanceTable);
})();
</script>
<script>
(function() {
    var mosipVerified = <?php echo $mosipVerified ? 'true' : 'false'; ?>;
    var modal = document.getElementById('mhVerifyModal');
    var btnYes = document.getElementById('mhVerifyYes');
    var btnNo = document.getElementById('mhVerifyNo');
    var clientAlert = document.getElementById('mhClientAlert');
    var pendingForm = null;

    function showClientError(msg) {
        if (!clientAlert) return;
        clientAlert.textContent = msg || '';
        clientAlert.style.display = msg ? '' : 'none';
        if (msg) {
            try { clientAlert.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
        }
    }

    function openModal(form) {
        pendingForm = form || null;
        if (!modal) return;
        modal.style.display = 'flex';
    }

    function closeModal() {
        if (!modal) return;
        modal.style.display = 'none';
        pendingForm = null;
    }

    function needsVerification(form) {
        if (mosipVerified) return false;
        if (!form) return false;
        var actionInput = form.querySelector('input[name="action"]');
        var action = actionInput && actionInput.value ? String(actionInput.value) : '';
        return action === 'create_request' || action === 'create_transfer';
    }

    document.querySelectorAll('form[method="POST"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!needsVerification(form)) return;
            e.preventDefault();
            e.stopPropagation();
            showClientError('');
            openModal(form);
        }, true);
    });

    if (btnYes) {
        btnYes.addEventListener('click', function() {
            try { sessionStorage.setItem('mh_post_kyc_return_to', window.location.href); } catch (e) {}
            window.location.href = '/auth/id/index.php';
        });
    }

    if (btnNo) {
        btnNo.addEventListener('click', function() {
            closeModal();
            showClientError('Verification failed, the request cannot proceed.');
        });
    }
})();
</script>
</body>
</html>
