<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/auth_functions.php';
require_once dirname(__DIR__, 2) . '/auth/persona_registry.php';
require_once dirname(__DIR__) . '/equity/db.php';

function mh_benefactors_min_tokens_required(): int
{
    if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_get_service_pricing')) {
        return 10;
    }
    try {
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdo);
        $row = mh_tokenomics_get_service_pricing($pdo, 'benefactors:min_tokens', 10);
        $v = (int)($row['tokens_per_unit'] ?? 10);
        return max(0, $v);
    } catch (Throwable $e) {
        return 10;
    }
}

function mh_benefactors_transfer_fee_tokens(): int
{
    if (!function_exists('mh_tokenomics_get_tokenomics_pdo') || !function_exists('mh_tokenomics_get_service_pricing')) {
        return 0;
    }
    try {
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdo);
        $row = mh_tokenomics_get_service_pricing($pdo, 'benefactors:transfer', 0);
        $v = (int)($row['tokens_per_unit'] ?? 0);
        return max(0, $v);
    } catch (Throwable $e) {
        return 0;
    }
}

function mh_benefactors_pdo(): PDO
{
    $pdo = getEquityConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    mh_benefactors_ensure_schema($pdo);
    return $pdo;
}

function mh_benefactors_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactors (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_username VARCHAR(255) NOT NULL,
        benefactor_username VARCHAR(255) NOT NULL,
        benefactor_name VARCHAR(255) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_benefactor_pair (owner_username, benefactor_username),
        KEY idx_benefactor_owner (owner_username),
        KEY idx_benefactor_user (benefactor_username)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_asset_rules (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_username VARCHAR(255) NOT NULL,
        benefactor_username VARCHAR(255) NOT NULL,
        asset_type VARCHAR(64) NOT NULL,
        mode VARCHAR(16) NOT NULL DEFAULT 'equal',
        value_num DECIMAL(18,6) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_benefactor_asset (owner_username, benefactor_username, asset_type),
        KEY idx_rule_owner_asset (owner_username, asset_type)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_claims (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_username VARCHAR(255) NOT NULL,
        initiated_by VARCHAR(255) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        kyc_room_id VARCHAR(96) NULL,
        snapshot_json LONGTEXT NULL,
        KEY idx_claim_owner (owner_username, created_at),
        KEY idx_claim_initiator (initiated_by, created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_claim_responses (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        claim_id BIGINT NOT NULL,
        benefactor_username VARCHAR(255) NOT NULL,
        kyc_room_id VARCHAR(96) NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        decided_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_claim_benefactor (claim_id, benefactor_username),
        KEY idx_response_claim (claim_id)
    )");
    try { $pdo->exec("ALTER TABLE benefactor_claim_responses ADD COLUMN kyc_room_id VARCHAR(96) NULL"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_claim_transfers (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        claim_id BIGINT NOT NULL,
        asset_type VARCHAR(64) NOT NULL,
        from_username VARCHAR(255) NOT NULL,
        to_username VARCHAR(255) NOT NULL,
        amount_num DECIMAL(18,6) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'queued',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_transfer_claim (claim_id, asset_type)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_plans (
        owner_username VARCHAR(255) NOT NULL PRIMARY KEY,
        inactivity_days INT NOT NULL DEFAULT 90,
        challenge_days INT NOT NULL DEFAULT 7,
        guardian_quorum INT NOT NULL DEFAULT 2,
        bond_amount_mtk INT NOT NULL DEFAULT 1000,
        tranche_count INT NOT NULL DEFAULT 6,
        tranche_interval_days INT NOT NULL DEFAULT 30,
        last_checkin_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_guardians (
        owner_username VARCHAR(255) NOT NULL,
        guardian_username VARCHAR(255) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (owner_username, guardian_username),
        KEY idx_guardian_user (guardian_username)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_guardian_stakes (
        owner_username VARCHAR(255) NOT NULL,
        guardian_username VARCHAR(255) NOT NULL,
        amount_mtk INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'locked',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (owner_username, guardian_username),
        KEY idx_stake_guardian (guardian_username)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_claims (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_username VARCHAR(255) NOT NULL,
        beneficiary_username VARCHAR(255) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        kyc_room_id VARCHAR(96) NULL,
        proof_verified_at DATETIME NULL,
        snapshot_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        eligible_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        challenge_until DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        halted_at DATETIME NULL,
        executed_at DATETIME NULL,
        KEY idx_claim_owner (owner_username, created_at),
        KEY idx_claim_beneficiary (beneficiary_username, created_at)
    )");
    try { $pdo->exec("ALTER TABLE benefactor_estate_claims ADD COLUMN kyc_room_id VARCHAR(96) NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE benefactor_estate_claims ADD COLUMN proof_verified_at DATETIME NULL"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_claim_votes (
        claim_id BIGINT NOT NULL,
        guardian_username VARCHAR(255) NOT NULL,
        decision VARCHAR(16) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (claim_id, guardian_username),
        KEY idx_vote_guardian (guardian_username)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_vesting (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        claim_id BIGINT NOT NULL,
        owner_username VARCHAR(255) NOT NULL,
        beneficiary_username VARCHAR(255) NOT NULL,
        asset_type VARCHAR(64) NOT NULL,
        total_amount BIGINT NOT NULL DEFAULT 0,
        released_amount BIGINT NOT NULL DEFAULT 0,
        released_tranches INT NOT NULL DEFAULT 0,
        tranche_count INT NOT NULL DEFAULT 1,
        tranche_interval_days INT NOT NULL DEFAULT 30,
        next_release_at DATETIME NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_vesting_due (beneficiary_username, next_release_at),
        KEY idx_vesting_claim (claim_id)
    )");
    try { $pdo->exec("ALTER TABLE benefactor_estate_vesting ADD COLUMN released_tranches INT NOT NULL DEFAULT 0 AFTER released_amount"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS benefactor_estate_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_username VARCHAR(255) NOT NULL,
        event_type VARCHAR(64) NOT NULL,
        payload_json LONGTEXT NULL,
        prev_hash CHAR(64) NOT NULL,
        event_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_event_owner (owner_username, created_at)
    )");
}

function mh_estate_event_append(PDO $pdo, string $owner, string $type, array $payload): void
{
    $owner = trim($owner);
    $type = trim($type);
    if ($owner === '' || $type === '') return;
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) $payloadJson = '{}';
    $prev = '0000000000000000000000000000000000000000000000000000000000000000';
    try {
        $stmt = $pdo->prepare("SELECT event_hash FROM benefactor_estate_events WHERE owner_username = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$owner]);
        $v = $stmt->fetchColumn();
        if (is_string($v) && trim($v) !== '') $prev = trim($v);
    } catch (Throwable $e) {}
    $now = gmdate('c');
    $hash = hash('sha256', $prev . '|' . $owner . '|' . $type . '|' . $payloadJson . '|' . $now);
    try {
        $stmt = $pdo->prepare("INSERT INTO benefactor_estate_events (owner_username, event_type, payload_json, prev_hash, event_hash, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$owner, $type, $payloadJson, $prev, $hash]);
    } catch (Throwable $e) {}
}

function mh_estate_get_plan(PDO $pdo, string $owner): array
{
    $owner = trim($owner);
    if ($owner === '') return [];
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO benefactor_estate_plans (owner_username) VALUES (?)");
        $stmt->execute([$owner]);
        $stmt = $pdo->prepare("SELECT * FROM benefactor_estate_plans WHERE owner_username = ? LIMIT 1");
        $stmt->execute([$owner]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    } catch (Throwable $e) {
        return [];
    }
}

function mh_estate_is_inactive(array $plan): bool
{
    $days = (int)($plan['inactivity_days'] ?? 90);
    $days = max(1, $days);
    $last = isset($plan['last_checkin_at']) ? strtotime((string)$plan['last_checkin_at']) : false;
    if ($last === false) return false;
    return (time() - (int)$last) >= ($days * 86400);
}

function mh_estate_plan_update(PDO $pdo, string $owner, int $inactivityDays, int $challengeDays, int $guardianQuorum, int $bondAmount, int $trancheCount, int $trancheIntervalDays): bool
{
    $owner = trim($owner);
    if ($owner === '') return false;
    $inactivityDays = max(1, $inactivityDays);
    $challengeDays = max(1, $challengeDays);
    $guardianQuorum = max(1, $guardianQuorum);
    $bondAmount = max(0, $bondAmount);
    $trancheCount = max(1, $trancheCount);
    $trancheIntervalDays = max(1, $trancheIntervalDays);
    try {
        $stmt = $pdo->prepare("INSERT INTO benefactor_estate_plans (owner_username, inactivity_days, challenge_days, guardian_quorum, bond_amount_mtk, tranche_count, tranche_interval_days, last_checkin_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE inactivity_days=VALUES(inactivity_days), challenge_days=VALUES(challenge_days), guardian_quorum=VALUES(guardian_quorum), bond_amount_mtk=VALUES(bond_amount_mtk),
                tranche_count=VALUES(tranche_count), tranche_interval_days=VALUES(tranche_interval_days)");
        $stmt->execute([$owner, $inactivityDays, $challengeDays, $guardianQuorum, $bondAmount, $trancheCount, $trancheIntervalDays]);
        mh_estate_event_append($pdo, $owner, 'plan_update', [
            'inactivity_days' => $inactivityDays,
            'challenge_days' => $challengeDays,
            'guardian_quorum' => $guardianQuorum,
            'bond_amount_mtk' => $bondAmount,
            'tranche_count' => $trancheCount,
            'tranche_interval_days' => $trancheIntervalDays,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function mh_estate_checkin(PDO $pdo, string $owner): bool
{
    $owner = trim($owner);
    if ($owner === '') return false;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO benefactor_estate_plans (owner_username) VALUES (?)");
        $stmt->execute([$owner]);
        $stmt = $pdo->prepare("UPDATE benefactor_estate_plans SET last_checkin_at = NOW() WHERE owner_username = ?");
        $stmt->execute([$owner]);
        mh_estate_event_append($pdo, $owner, 'checkin', []);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function mh_estate_guardians_list(PDO $pdo, string $owner): array
{
    $owner = trim($owner);
    if ($owner === '') return [];
    try {
        $stmt = $pdo->prepare("SELECT g.owner_username, g.guardian_username, g.status, g.created_at, COALESCE(s.amount_mtk, 0) AS staked_mtk, COALESCE(s.status, '') AS stake_status
            FROM benefactor_estate_guardians g
            LEFT JOIN benefactor_estate_guardian_stakes s ON s.owner_username = g.owner_username AND s.guardian_username = g.guardian_username
            WHERE g.owner_username = ?
            ORDER BY g.created_at ASC");
        $stmt->execute([$owner]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mh_estate_guardian_add(PDO $pdo, string $owner, string $guardian): bool
{
    $owner = trim($owner);
    $guardian = trim($guardian);
    if ($owner === '' || $guardian === '' || strcasecmp($owner, $guardian) === 0) return false;
    try {
        $stmt = $pdo->prepare("INSERT INTO benefactor_estate_guardians (owner_username, guardian_username, status) VALUES (?, ?, 'active')
            ON DUPLICATE KEY UPDATE status='active'");
        $stmt->execute([$owner, $guardian]);
        mh_estate_event_append($pdo, $owner, 'guardian_add', ['guardian' => $guardian]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function mh_estate_guardian_remove(PDO $pdo, string $owner, string $guardian): bool
{
    $owner = trim($owner);
    $guardian = trim($guardian);
    if ($owner === '' || $guardian === '') return false;
    try {
        $stmt = $pdo->prepare("UPDATE benefactor_estate_guardians SET status='removed' WHERE owner_username = ? AND guardian_username = ?");
        $stmt->execute([$owner, $guardian]);
        mh_estate_event_append($pdo, $owner, 'guardian_remove', ['guardian' => $guardian]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function mh_estate_guardian_lock_stake(PDO $pdo, string $owner, string $guardian): array
{
    $owner = trim($owner);
    $guardian = trim($guardian);
    if ($owner === '' || $guardian === '') return ['ok' => false, 'error' => 'invalid_request'];

    $plan = mh_estate_get_plan($pdo, $owner);
    $bond = max(0, (int)($plan['bond_amount_mtk'] ?? 0));
    if ($bond <= 0) return ['ok' => false, 'error' => 'bond_disabled'];

    try {
        $stmt = $pdo->prepare("SELECT status FROM benefactor_estate_guardians WHERE owner_username = ? AND guardian_username = ? LIMIT 1");
        $stmt->execute([$owner, $guardian]);
        $gst = strtolower(trim((string)$stmt->fetchColumn()));
        if ($gst !== 'active') return ['ok' => false, 'error' => 'not_guardian'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'not_guardian'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT amount_mtk, status FROM benefactor_estate_guardian_stakes WHERE owner_username = ? AND guardian_username = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$owner, $guardian]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $curAmt = is_array($row) ? (int)($row['amount_mtk'] ?? 0) : 0;
        $curStatus = is_array($row) ? strtolower(trim((string)($row['status'] ?? 'locked'))) : '';
        if ($curAmt >= $bond && $curStatus === 'locked') {
            $pdo->commit();
            return ['ok' => true, 'bond' => $bond, 'staked' => $curAmt];
        }
        $need = max(0, $bond - max(0, $curAmt));
        if ($need <= 0) $need = 0;

        $bal = function_exists('mh_get_token_balance') ? mh_get_token_balance($guardian) : null;
        $bal = is_int($bal) ? $bal : 0;
        if ($bal < $need) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'insufficient_tokens', 'need' => $need, 'have' => $bal];
        }
        if ($need > 0 && (!function_exists('mh_debit_tokens') || !mh_debit_tokens($guardian, $need, 'benefactors:guardian_stake_lock', [
            'owner_username' => $owner,
            'guardian_username' => $guardian,
            'amount_mtk' => $need,
        ]))) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'stake_debit_failed'];
        }

        $stmt = $pdo->prepare("INSERT INTO benefactor_estate_guardian_stakes (owner_username, guardian_username, amount_mtk, status)
            VALUES (?, ?, ?, 'locked')
            ON DUPLICATE KEY UPDATE amount_mtk = amount_mtk + VALUES(amount_mtk), status = 'locked'");
        $stmt->execute([$owner, $guardian, $need]);
        mh_estate_event_append($pdo, $owner, 'stake_lock', ['guardian' => $guardian, 'amount_mtk' => $need]);
        $pdo->commit();

        $stmt = $pdo->prepare("SELECT amount_mtk FROM benefactor_estate_guardian_stakes WHERE owner_username = ? AND guardian_username = ? LIMIT 1");
        $stmt->execute([$owner, $guardian]);
        $staked = (int)$stmt->fetchColumn();
        return ['ok' => true, 'bond' => $bond, 'staked' => $staked];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($need > 0 && function_exists('mh_credit_tokens')) {
            mh_credit_tokens($guardian, $need, 'benefactors:guardian_stake_refund', [
                'owner_username' => $owner,
                'guardian_username' => $guardian,
                'amount_mtk' => $need,
            ]);
        }
        return ['ok' => false, 'error' => 'stake_lock_failed'];
    }
}

function mh_estate_guardian_release_stake(PDO $pdo, string $owner, string $guardian): bool
{
    $owner = trim($owner);
    $guardian = trim($guardian);
    if ($owner === '' || $guardian === '') return false;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT amount_mtk, status FROM benefactor_estate_guardian_stakes WHERE owner_username = ? AND guardian_username = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$owner, $guardian]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $pdo->commit();
            return false;
        }
        $amt = (int)($row['amount_mtk'] ?? 0);
        $st = strtolower(trim((string)($row['status'] ?? 'locked')));
        if ($amt <= 0 || $st !== 'locked') {
            $pdo->commit();
            return false;
        }

        if (!function_exists('mh_credit_tokens') || !mh_credit_tokens($guardian, $amt, 'benefactors:guardian_stake_release', [
            'owner_username' => $owner,
            'guardian_username' => $guardian,
            'amount_mtk' => $amt,
        ])) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare("UPDATE benefactor_estate_guardian_stakes SET amount_mtk = 0, status = 'released' WHERE owner_username = ? AND guardian_username = ?")->execute([$owner, $guardian]);
        mh_estate_event_append($pdo, $owner, 'stake_release', ['guardian' => $guardian, 'amount_mtk' => $amt]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

function mh_estate_claim_create(PDO $pdo, string $owner, string $beneficiary): array
{
    $owner = trim($owner);
    $beneficiary = trim($beneficiary);
    if ($owner === '' || $beneficiary === '' || strcasecmp($owner, $beneficiary) === 0) return ['ok' => false, 'error' => 'invalid_request'];

    $plan = mh_estate_get_plan($pdo, $owner);
    if (empty($plan) || !mh_estate_is_inactive($plan)) return ['ok' => false, 'error' => 'owner_not_inactive'];

    try {
        $stmt = $pdo->prepare("SELECT 1 FROM benefactors WHERE owner_username = ? AND benefactor_username = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$owner, $beneficiary]);
        if (!(bool)$stmt->fetchColumn()) return ['ok' => false, 'error' => 'not_active_benefactor'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'not_active_benefactor'];
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM benefactor_estate_claims WHERE owner_username = ? AND beneficiary_username = ? AND status IN ('pending','approved') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$owner, $beneficiary]);
        $existing = (int)$stmt->fetchColumn();
        if ($existing > 0) return ['ok' => true, 'claim_id' => $existing, 'status' => 'existing'];
    } catch (Throwable $e) {}

    $challengeDays = max(1, (int)($plan['challenge_days'] ?? 7));
    $snap = mh_benefactors_asset_snapshot($owner);
    $snapJson = json_encode($snap, JSON_UNESCAPED_SLASHES);
    if (!is_string($snapJson)) $snapJson = '[]';
    try {
        $stmt = $pdo->prepare("INSERT INTO benefactor_estate_claims (owner_username, beneficiary_username, status, snapshot_json, eligible_at, challenge_until)
            VALUES (?, ?, 'pending', ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY))");
        $stmt->execute([$owner, $beneficiary, $snapJson, $challengeDays]);
        $id = (int)$pdo->lastInsertId();
        $safeBu = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $beneficiary);
        $safeBu = trim((string)$safeBu, '_');
        $roomId = 'estate_claim_' . $id . '_' . $safeBu;
        if (strlen($roomId) > 64) $roomId = substr($roomId, 0, 64);
        try {
            $pdo->prepare("UPDATE benefactor_estate_claims SET kyc_room_id = ? WHERE id = ?")->execute([$roomId, $id]);
        } catch (Throwable $e) {}
        mh_estate_event_append($pdo, $owner, 'claim_create', ['claim_id' => $id, 'beneficiary' => $beneficiary, 'challenge_days' => $challengeDays]);
        return ['ok' => true, 'claim_id' => $id, 'status' => 'pending', 'kyc_room_id' => $roomId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'claim_create_failed'];
    }
}

function mh_estate_claims_for_guardian(PDO $pdo, string $guardian): array
{
    $guardian = trim($guardian);
    if ($guardian === '') return [];
    try {
        $stmt = $pdo->prepare("SELECT c.*, p.guardian_quorum, p.bond_amount_mtk
            FROM benefactor_estate_claims c
            JOIN benefactor_estate_guardians g ON g.owner_username = c.owner_username AND g.guardian_username = ? AND g.status = 'active'
            JOIN benefactor_estate_plans p ON p.owner_username = c.owner_username
            WHERE c.status = 'pending'
            ORDER BY c.created_at DESC
            LIMIT 100");
        $stmt->execute([$guardian]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function mh_estate_claim_vote(PDO $pdo, int $claimId, string $guardian, string $decision): array
{
    $guardian = trim($guardian);
    $decision = strtolower(trim($decision));
    if ($claimId < 1 || $guardian === '' || !in_array($decision, ['accept', 'reject'], true)) return ['ok' => false, 'error' => 'invalid_request'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT c.owner_username, c.beneficiary_username, c.status, c.snapshot_json, c.challenge_until, p.guardian_quorum, p.bond_amount_mtk, p.tranche_count, p.tranche_interval_days
            FROM benefactor_estate_claims c
            JOIN benefactor_estate_plans p ON p.owner_username = c.owner_username
            WHERE c.id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$claimId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($c) || strtolower(trim((string)($c['status'] ?? ''))) !== 'pending') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'claim_not_pending'];
        }
        $owner = trim((string)($c['owner_username'] ?? ''));
        $beneficiary = trim((string)($c['beneficiary_username'] ?? ''));
        $quorum = max(1, (int)($c['guardian_quorum'] ?? 2));
        $bond = max(0, (int)($c['bond_amount_mtk'] ?? 0));
        $trancheCount = max(1, (int)($c['tranche_count'] ?? 6));
        $trancheIntervalDays = max(1, (int)($c['tranche_interval_days'] ?? 30));
        $challengeUntil = trim((string)($c['challenge_until'] ?? ''));

        $stmt = $pdo->prepare("SELECT status FROM benefactor_estate_guardians WHERE owner_username = ? AND guardian_username = ? LIMIT 1");
        $stmt->execute([$owner, $guardian]);
        if (strtolower(trim((string)$stmt->fetchColumn())) !== 'active') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'not_guardian'];
        }

        if (!mh_benefactors_user_kyc_is_verified($pdo, $guardian)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'guardian_kyc_not_verified'];
        }

        if ($bond > 0) {
            $stmt = $pdo->prepare("SELECT amount_mtk, status FROM benefactor_estate_guardian_stakes WHERE owner_username = ? AND guardian_username = ? LIMIT 1");
            $stmt->execute([$owner, $guardian]);
            $sr = $stmt->fetch(PDO::FETCH_ASSOC);
            $amt = is_array($sr) ? (int)($sr['amount_mtk'] ?? 0) : 0;
            $st = is_array($sr) ? strtolower(trim((string)($sr['status'] ?? ''))) : '';
            if ($st !== 'locked' || $amt < $bond) {
                $pdo->rollBack();
                return ['ok' => false, 'error' => 'stake_required', 'bond' => $bond, 'staked' => $amt];
            }
        }

        $stmt = $pdo->prepare("INSERT INTO benefactor_estate_claim_votes (claim_id, guardian_username, decision) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE decision = VALUES(decision), created_at = NOW()");
        $stmt->execute([$claimId, $guardian, $decision]);
        mh_estate_event_append($pdo, $owner, 'guardian_vote', ['claim_id' => $claimId, 'guardian' => $guardian, 'decision' => $decision]);

        $stmt = $pdo->prepare("SELECT SUM(CASE WHEN decision = 'accept' THEN 1 ELSE 0 END) AS accepts FROM benefactor_estate_claim_votes WHERE claim_id = ?");
        $stmt->execute([$claimId]);
        $accepts = (int)$stmt->fetchColumn();

        if ($accepts < $quorum) {
            $pdo->commit();
            return ['ok' => true, 'status' => 'pending', 'accepts' => $accepts, 'quorum' => $quorum];
        }

        $roomId = trim((string)($c['kyc_room_id'] ?? ''));
        $proofOk = false;
        if ($roomId !== '') {
            try {
                $stmt = $pdo->prepare("SELECT status, evidence_json FROM user_kyc_sessions WHERE username = ? AND session_id = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$beneficiary, $roomId]);
                $sessRow = $stmt->fetch(PDO::FETCH_ASSOC);
                $sessStatus = is_array($sessRow) ? strtolower(trim((string)($sessRow['status'] ?? ''))) : '';
                $evRaw = is_array($sessRow) ? (string)($sessRow['evidence_json'] ?? '') : '';
                $ev = [];
                if ($evRaw !== '') {
                    $d = json_decode($evRaw, true);
                    if (is_array($d)) $ev = $d;
                }
                $hashes = [];
                if (is_array($ev) && isset($ev['hashes']) && is_array($ev['hashes'])) {
                    $hashes = $ev['hashes'];
                } elseif (is_array($ev)) {
                    $hashes = $ev;
                }
                $proofOk = ($sessStatus === 'verified' && !empty($hashes['selfie_video.mp4']));
            } catch (Throwable $e) {
                $proofOk = false;
            }
        }
        if (!$proofOk) {
            $pdo->commit();
            return ['ok' => true, 'status' => 'pending', 'accepts' => $accepts, 'quorum' => $quorum, 'needs_proof' => true, 'kyc_room_id' => $roomId];
        }
        try { $pdo->prepare("UPDATE benefactor_estate_claims SET proof_verified_at = COALESCE(proof_verified_at, NOW()) WHERE id = ?")->execute([$claimId]); } catch (Throwable $e) {}

        $snapshot = [];
        $raw = isset($c['snapshot_json']) ? (string)$c['snapshot_json'] : '';
        if ($raw !== '') {
            $d = json_decode($raw, true);
            if (is_array($d)) $snapshot = $d;
        }

        $stmt = $pdo->prepare("SELECT benefactor_username FROM benefactors WHERE owner_username = ? AND status = 'active'");
        $stmt->execute([$owner]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $benefs = [];
        foreach ($rows as $r) {
            $u = isset($r['benefactor_username']) ? trim((string)$r['benefactor_username']) : '';
            if ($u !== '') $benefs[] = ['benefactor_username' => $u];
        }
        $stmt = $pdo->prepare("SELECT * FROM benefactor_asset_rules WHERE owner_username = ?");
        $stmt->execute([$owner]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $allocTotals = [];
        foreach ($snapshot as $a) {
            $t = isset($a['type']) ? trim((string)$a['type']) : '';
            $qtyRaw = isset($a['qty']) ? (float)$a['qty'] : 0.0;
            $qty = (int)floor(max(0.0, $qtyRaw));
            if ($t === '' || $qty <= 0) continue;
            $pctMap = mh_benefactors_compute_allocations($benefs, $rules, $t);
            $pct = isset($pctMap[$beneficiary]) ? (float)$pctMap[$beneficiary] : 0.0;
            if ($pct <= 0) continue;
            $amt = (int)floor($qty * ($pct / 100.0));
            if ($amt <= 0) continue;
            $allocTotals[] = ['asset_type' => $t, 'amount' => $amt];
        }

        if (!empty($allocTotals)) {
            $ins = $pdo->prepare("INSERT INTO benefactor_estate_vesting (claim_id, owner_username, beneficiary_username, asset_type, total_amount, released_amount, released_tranches, tranche_count, tranche_interval_days, next_release_at, status)
                VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, 'active')");
            foreach ($allocTotals as $x) {
                $ins->execute([$claimId, $owner, $beneficiary, (string)$x['asset_type'], (int)$x['amount'], $trancheCount, $trancheIntervalDays, $challengeUntil !== '' ? $challengeUntil : gmdate('Y-m-d H:i:s')]);
            }
        }

        $pdo->prepare("UPDATE benefactor_estate_claims SET status = 'approved' WHERE id = ?")->execute([$claimId]);
        mh_estate_event_append($pdo, $owner, 'claim_approved', ['claim_id' => $claimId, 'accepts' => $accepts, 'quorum' => $quorum]);
        $pdo->commit();
        return ['ok' => true, 'status' => 'approved', 'accepts' => $accepts, 'quorum' => $quorum];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'vote_failed'];
    }
}

function mh_estate_claim_halt(PDO $pdo, int $claimId, string $owner): array
{
    $owner = trim($owner);
    if ($claimId < 1 || $owner === '') return ['ok' => false, 'error' => 'invalid_request'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT owner_username, status, challenge_until FROM benefactor_estate_claims WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$claimId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($c) || strcasecmp(trim((string)($c['owner_username'] ?? '')), $owner) !== 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'not_owner'];
        }
        $status = strtolower(trim((string)($c['status'] ?? 'pending')));
        if (!in_array($status, ['pending', 'approved'], true)) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'not_haltable'];
        }
        $until = isset($c['challenge_until']) ? strtotime((string)$c['challenge_until']) : 0;
        if ($until > 0 && time() > $until) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'challenge_window_closed'];
        }

        $pdo->prepare("UPDATE benefactor_estate_claims SET status = 'halted', halted_at = NOW() WHERE id = ?")->execute([$claimId]);

        $stmt = $pdo->prepare("SELECT guardian_username, decision FROM benefactor_estate_claim_votes WHERE claim_id = ?");
        $stmt->execute([$claimId]);
        $votes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $approvers = [];
        $nonApprovers = [];
        foreach ($votes as $v) {
            $g = isset($v['guardian_username']) ? trim((string)$v['guardian_username']) : '';
            $d = isset($v['decision']) ? strtolower(trim((string)$v['decision'])) : '';
            if ($g === '') continue;
            if ($d === 'accept') $approvers[$g] = true; else $nonApprovers[$g] = true;
        }

        $stmt = $pdo->prepare("SELECT guardian_username, amount_mtk, status FROM benefactor_estate_guardian_stakes WHERE owner_username = ? FOR UPDATE");
        $stmt->execute([$owner]);
        $stakes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($stakes as $s) {
            $g = isset($s['guardian_username']) ? trim((string)$s['guardian_username']) : '';
            $amt = (int)($s['amount_mtk'] ?? 0);
            $st = strtolower(trim((string)($s['status'] ?? 'locked')));
            if ($g === '' || $amt <= 0 || $st !== 'locked') continue;
            if (!empty($approvers[$g])) {
                if (!function_exists('mh_credit_tokens') || !mh_credit_tokens($owner, $amt, 'benefactors:guardian_stake_slash', [
                    'guardian_username' => $g,
                    'claim_id' => $claimId,
                ])) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'halt_failed'];
                }
                $pdo->prepare("UPDATE benefactor_estate_guardian_stakes SET amount_mtk = 0, status = 'slashed' WHERE owner_username = ? AND guardian_username = ?")->execute([$owner, $g]);
                mh_estate_event_append($pdo, $owner, 'stake_slash', ['guardian' => $g, 'amount_mtk' => $amt, 'claim_id' => $claimId]);
            } else {
                if (!function_exists('mh_credit_tokens') || !mh_credit_tokens($g, $amt, 'benefactors:guardian_stake_release', [
                    'owner_username' => $owner,
                    'guardian_username' => $g,
                    'claim_id' => $claimId,
                ])) {
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'halt_failed'];
                }
                $pdo->prepare("UPDATE benefactor_estate_guardian_stakes SET amount_mtk = 0, status = 'released' WHERE owner_username = ? AND guardian_username = ?")->execute([$owner, $g]);
                mh_estate_event_append($pdo, $owner, 'stake_release', ['guardian' => $g, 'amount_mtk' => $amt, 'claim_id' => $claimId]);
            }
        }

        mh_estate_event_append($pdo, $owner, 'claim_halted', ['claim_id' => $claimId]);
        $pdo->commit();
        return ['ok' => true, 'status' => 'halted'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => 'halt_failed'];
    }
}

function mh_estate_apply_due_tranches(PDO $pdo, string $beneficiary): array
{
    $beneficiary = trim($beneficiary);
    if ($beneficiary === '') return ['ok' => true, 'applied' => 0];

    $applied = 0;
    try {
        $stmt = $pdo->prepare("SELECT v.id, v.claim_id, v.owner_username, v.beneficiary_username, v.asset_type, v.total_amount, v.released_amount, v.released_tranches, v.tranche_count, v.tranche_interval_days, v.next_release_at
            FROM benefactor_estate_vesting v
            JOIN benefactor_estate_claims c ON c.id = v.claim_id AND c.status = 'approved'
            WHERE v.beneficiary_username = ? AND v.status = 'active' AND v.next_release_at <= NOW()
            ORDER BY v.next_release_at ASC
            LIMIT 25");
        $stmt->execute([$beneficiary]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['ok' => true, 'applied' => 0];
    }

    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        $claimId = (int)($r['claim_id'] ?? 0);
        $owner = trim((string)($r['owner_username'] ?? ''));
        $asset = trim((string)($r['asset_type'] ?? ''));
        $total = (int)($r['total_amount'] ?? 0);
        $released = (int)($r['released_amount'] ?? 0);
        $relTr = (int)($r['released_tranches'] ?? 0);
        $trCount = max(1, (int)($r['tranche_count'] ?? 1));
        $intervalDays = max(1, (int)($r['tranche_interval_days'] ?? 30));
        $remaining = max(0, $total - $released);
        if ($id < 1 || $claimId < 1 || $owner === '' || $asset === '' || $remaining <= 0) {
            try { $pdo->prepare("UPDATE benefactor_estate_vesting SET status='done' WHERE id = ?")->execute([$id]); } catch (Throwable $e) {}
            continue;
        }

        $trLeft = max(1, $trCount - $relTr);
        $amt = (int)ceil($remaining / $trLeft);
        $amt = max(1, min($amt, $remaining));

        $sent = 0;
        if ($asset === 'utility_token') {
            try {
                $have = function_exists('mh_get_token_balance') ? mh_get_token_balance($owner) : null;
                $have = is_int($have) ? $have : 0;
                $sent = min($amt, max(0, $have));
                if ($sent > 0 && (!function_exists('mh_transfer_tokens') || !mh_transfer_tokens($owner, $beneficiary, $sent, 'benefactors:estate_tranche', [
                    'claim_id' => $claimId,
                    'vesting_id' => $id,
                    'asset_type' => $asset,
                ]))) {
                    $sent = 0;
                }
            } catch (Throwable $e) {
                $sent = 0;
            }
        } elseif (str_starts_with($asset, 'equity_')) {
            try {
                $ok = mh_benefactors_transfer_equity_units($owner, $beneficiary, $amt, $asset);
                $sent = $ok ? $amt : 0;
            } catch (Throwable $e) {
                $sent = 0;
            }
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT released_amount, released_tranches, total_amount FROM benefactor_estate_vesting WHERE id = ? LIMIT 1 FOR UPDATE");
            $stmt->execute([$id]);
            $cur = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($cur)) {
                $pdo->rollBack();
                continue;
            }
            $curRel = (int)($cur['released_amount'] ?? 0);
            $curTr = (int)($cur['released_tranches'] ?? 0);
            $curTot = (int)($cur['total_amount'] ?? 0);
            $newRel = $curRel + max(0, $sent);
            $newTr = $curTr + ($sent > 0 ? 1 : 0);
            $done = $newRel >= $curTot;
            $next = $done ? null : date('Y-m-d H:i:s', time() + ($intervalDays * 86400));
            if ($done) {
                $pdo->prepare("UPDATE benefactor_estate_vesting SET released_amount = ?, released_tranches = ?, status='done' WHERE id = ?")->execute([$newRel, $newTr, $id]);
            } else {
                $pdo->prepare("UPDATE benefactor_estate_vesting SET released_amount = ?, released_tranches = ?, next_release_at = ? WHERE id = ?")->execute([$newRel, $newTr, $next, $id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }

        if ($sent > 0) $applied++;
    }

    try {
        $stmt = $pdo->prepare("SELECT claim_id FROM benefactor_estate_vesting v JOIN benefactor_estate_claims c ON c.id = v.claim_id
            WHERE v.beneficiary_username = ? AND c.status = 'approved' GROUP BY claim_id");
        $stmt->execute([$beneficiary]);
        $claimIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        foreach ($claimIds as $cidRaw) {
            $cid = (int)$cidRaw;
            if ($cid < 1) continue;
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM benefactor_estate_vesting WHERE claim_id = ? AND status = 'active'");
            $stmt2->execute([$cid]);
            $left = (int)$stmt2->fetchColumn();
            if ($left === 0) {
                $pdo->prepare("UPDATE benefactor_estate_claims SET status = 'executed', executed_at = NOW() WHERE id = ? AND status = 'approved'")->execute([$cid]);
            }
        }
    } catch (Throwable $e) {}

    return ['ok' => true, 'applied' => $applied];
}

function mh_benefactors_get_user_row(PDO $pdo, string $username): ?array
{
    $username = trim($username);
    if ($username === '') return null;

    $u = $username;
    if (stripos($u, 'user:') === 0) {
        $u = trim(substr($u, 5));
    }
    if ($u === '') return null;

    $displayName = '';
    $realFirst = '';
    $realLast = '';
    try {
        if (function_exists('mh_persona_registry_pdo') && function_exists('mh_user_directory_get')) {
            $pdoReg = mh_persona_registry_pdo();
            $row = mh_user_directory_get($pdoReg, $u);
            if (is_array($row)) {
                $u = isset($row['username']) ? trim((string)$row['username']) : $u;
                $displayName = isset($row['display_name']) ? trim((string)$row['display_name']) : '';
                $realFirst = isset($row['real_first_name']) ? trim((string)$row['real_first_name']) : '';
                $realLast = isset($row['real_last_name']) ? trim((string)$row['real_last_name']) : '';
            }
        }
    } catch (Throwable $e) {}

    if ($displayName === '' && ($realFirst !== '' || $realLast !== '')) {
        $displayName = trim($realFirst . ' ' . $realLast);
    }

    if ($displayName === '' && function_exists('mh_persona_registry_pdo') && function_exists('mh_user_directory_get')) {
        try {
            $pdoReg = mh_persona_registry_pdo();
            $dir = mh_user_directory_get($pdoReg, $u);
            if (is_array($dir)) {
                $displayName = isset($dir['display_name']) ? trim((string)$dir['display_name']) : '';
            }
        } catch (Throwable $e) {}
    }

    if ($displayName === '' && $realFirst === '' && $realLast === '') {
        return null;
    }

    $tokensTok = null;
    if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_ensure_schema') && function_exists('mh_tokenomics_seed_utility_token')) {
        try {
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            mh_tokenomics_ensure_schema($pdoTok);
            $utilityClassId = (int)mh_tokenomics_seed_utility_token($pdoTok);
            if ($utilityClassId > 0) {
                $stmt = $pdoTok->prepare("SELECT MAX(units_owned) FROM mh_asset_ledger WHERE username = ? AND asset_class_id = ? LIMIT 1");
                $stmt->execute([$u, $utilityClassId]);
                $v = $stmt->fetchColumn();
                if ($v !== false) {
                    $tokensTok = max(0, (int)$v);
                }
                if (!is_int($tokensTok) || $tokensTok < 1) {
                    $stmt = $pdoTok->prepare("SELECT MAX(units_owned) FROM mh_asset_ledger WHERE LOWER(username) = LOWER(?) AND asset_class_id = ? LIMIT 1");
                    $stmt->execute([$u, $utilityClassId]);
                    $v = $stmt->fetchColumn();
                    if ($v !== false) {
                        $tokensTok = max(0, (int)$v);
                    }
                }
            }
        } catch (Throwable $e) {
            $tokensTok = null;
        }
    }

    $tokens = 0;
    if (is_int($tokensTok)) {
        $tokens = max($tokens, $tokensTok);
    }
    if (function_exists('mh_get_token_balance')) {
        $candidates = array_values(array_unique(array_filter([
            $u,
            strtolower($u),
            strtoupper($u),
        ], fn($v) => is_string($v) && trim($v) !== '')));
        foreach ($candidates as $cand) {
            $bal = mh_get_token_balance($cand);
            if (is_int($bal)) {
                $tokens = max($tokens, $bal);
            }
        }
    }

    return [
        'username' => $u,
        'name' => $displayName,
        'real_first_name' => $realFirst,
        'real_last_name' => $realLast,
        'tokens' => $tokens,
    ];
}

function mh_benefactors_asset_snapshot(string $username): array
{
    $username = trim($username);
    $assets = [];

    try {
        $tokens = 0;
        if (function_exists('mh_get_token_balance')) {
            $bal = mh_get_token_balance($username);
            if (is_int($bal)) {
                $tokens = $bal;
            }
        }
        try {
            if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_ensure_schema') && function_exists('mh_tokenomics_seed_utility_token')) {
                $pdoTok = mh_tokenomics_get_tokenomics_pdo();
                mh_tokenomics_ensure_schema($pdoTok);
                $utilityClassId = (int)mh_tokenomics_seed_utility_token($pdoTok);
                if ($utilityClassId > 0) {
                    $stmt = $pdoTok->prepare("SELECT MAX(units_owned) FROM mh_asset_ledger WHERE LOWER(username) = LOWER(?) AND asset_class_id = ? LIMIT 1");
                    $stmt->execute([$username, $utilityClassId]);
                    $v = $stmt->fetchColumn();
                    if ($v !== false) {
                        $tokens = max($tokens, max(0, (int)$v));
                    }
                }
            }
        } catch (Throwable $e) {}
        if ($tokens > 0) {
            $assets[] = ['type' => 'utility_token', 'label' => 'Utility Token', 'qty' => $tokens];
        }
    } catch (Throwable $e) {}

    try {
        $pdoEquity = getEquityConnection();
        if ($pdoEquity instanceof PDO) {
            $stmt = $pdoEquity->prepare("SELECT c.id, c.name, c.fractional_units_per_share, SUM(l.units_owned) as units
                FROM equity_ledger l JOIN equity_classes c ON l.class_id = c.id
                WHERE l.username = ?
                GROUP BY c.id, c.name, c.fractional_units_per_share
            ");
            $stmt->execute([$username]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ordinaryUnits = 0;
            $preferenceUnits = 0;
            $totalUnits = 0;
            foreach ($rows as $r) {
                $name = strtolower(trim((string)($r['name'] ?? '')));
                $u = (int)($r['units'] ?? 0);
                if ($u < 1) continue;
                $totalUnits += $u;
                if (strpos($name, 'ordinary') !== false || strpos($name, 'common') !== false) $ordinaryUnits += $u;
                if (strpos($name, 'preference') !== false || strpos($name, 'preferred') !== false) $preferenceUnits += $u;
            }
            if ($totalUnits > 0) {
                $assets[] = ['type' => 'equity_coins', 'label' => 'Equity Coins', 'qty' => $totalUnits];
            }
            if ($ordinaryUnits > 0) {
                $assets[] = ['type' => 'equity_ordinary_coins', 'label' => 'Equity Ordinary (Coins)', 'qty' => $ordinaryUnits];
            }
            if ($preferenceUnits > 0) {
                $assets[] = ['type' => 'equity_preference_coins', 'label' => 'Equity Preference (Coins)', 'qty' => $preferenceUnits];
            }
        }
    } catch (Throwable $e) {}

    return $assets;
}

function mh_benefactors_compute_allocations(array $benefactors, array $rules, string $assetType): array
{
    $active = [];
    foreach ($benefactors as $b) {
        $u = isset($b['benefactor_username']) ? trim((string)$b['benefactor_username']) : '';
        if ($u !== '') $active[$u] = true;
    }
    if (empty($active)) return [];

    $rows = [];
    foreach ($rules as $r) {
        $u = isset($r['benefactor_username']) ? trim((string)$r['benefactor_username']) : '';
        $t = isset($r['asset_type']) ? trim((string)$r['asset_type']) : '';
        if ($u === '' || $t !== $assetType) continue;
        if (empty($active[$u])) continue;
        $mode = isset($r['mode']) ? strtolower(trim((string)$r['mode'])) : 'equal';
        $val = isset($r['value_num']) ? (float)$r['value_num'] : null;
        $rows[$u] = ['mode' => $mode, 'value' => $val];
    }

    $modes = array_values(array_unique(array_map(fn($x) => $x['mode'] ?? 'equal', $rows)));
    $allMode = in_array('all', $modes, true);

    $alloc = [];
    $acceptedUsers = array_keys($active);
    if ($allMode) {
        $n = max(1, count($acceptedUsers));
        foreach ($acceptedUsers as $u) {
            $alloc[$u] = 100.0 / $n;
        }
        return $alloc;
    }

    $sumPct = 0.0;
    $pctUsers = [];
    foreach ($acceptedUsers as $u) {
        $rule = $rows[$u] ?? ['mode' => 'equal', 'value' => null];
        if ($rule['mode'] === 'percent' && $rule['value'] !== null) {
            $v = max(0.0, min(100.0, (float)$rule['value']));
            $alloc[$u] = $v;
            $sumPct += $v;
            $pctUsers[] = $u;
        }
    }

    if ($sumPct > 100.0 && $sumPct > 0.0) {
        foreach ($pctUsers as $u) {
            $alloc[$u] = ($alloc[$u] / $sumPct) * 100.0;
        }
        $sumPct = 100.0;
    }

    $remaining = max(0.0, 100.0 - $sumPct);
    $targets = [];
    foreach ($acceptedUsers as $u) {
        if (isset($alloc[$u])) continue;
        $targets[] = $u;
    }
    if (empty($targets)) return $alloc;

    $n = max(1, count($targets));
    foreach ($targets as $u) {
        $alloc[$u] = $remaining / $n;
    }
    return $alloc;
}

function mh_benefactors_sweep_expired(PDO $pdo): void
{
    try {
        $pdo->exec("UPDATE benefactor_claim_responses SET status = 'expired'
            WHERE status = 'pending' AND created_at < (NOW() - INTERVAL 90 DAY)");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("UPDATE benefactor_claim_responses r
            JOIN benefactor_claims c ON c.id = r.claim_id
            LEFT JOIN benefactors b ON b.owner_username = c.owner_username AND b.benefactor_username = r.benefactor_username AND b.status = 'active'
            SET r.status = 'revoked', r.decided_at = COALESCE(r.decided_at, NOW())
            WHERE c.status = 'open' AND r.status = 'pending' AND b.id IS NULL");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("UPDATE benefactor_claims c
            LEFT JOIN benefactors b ON b.owner_username = c.owner_username AND b.benefactor_username = c.initiated_by AND b.status = 'active'
            SET c.status = 'void'
            WHERE c.status = 'open' AND b.id IS NULL");
    } catch (Throwable $e) {}

    try {
        $pdo->exec("UPDATE benefactor_claim_responses r
            JOIN benefactor_claims c ON c.id = r.claim_id
            SET r.status = 'revoked', r.decided_at = COALESCE(r.decided_at, NOW())
            WHERE c.status = 'void' AND r.status <> 'revoked'");
    } catch (Throwable $e) {}
}

function mh_benefactors_my_claims(PDO $pdo, string $username): array
{
    $username = trim($username);
    if ($username === '') return [];
    $stmt = $pdo->prepare("SELECT c.id, c.owner_username, c.initiated_by, c.status, c.created_at, r.kyc_room_id
        FROM benefactor_claims c
        JOIN benefactors b ON b.owner_username = c.owner_username AND b.benefactor_username = c.initiated_by AND b.status = 'active'
        LEFT JOIN benefactor_claim_responses r ON r.claim_id = c.id AND r.benefactor_username = ?
        WHERE c.initiated_by = ? AND c.status = 'open'
        ORDER BY c.created_at DESC
        LIMIT 50");
    $stmt->execute([$username, $username]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function mh_benefactors_user_kyc_is_verified(PDO $pdo, string $username): bool
{
    $username = trim($username);
    if ($username === '') return false;
    try {
        $stmt = $pdo->prepare("SELECT status, expires_at FROM user_kyc WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return false;
        $st = strtolower(trim((string)($row['status'] ?? '')));
        if ($st !== 'verified') return false;
        $exp = isset($row['expires_at']) ? strtotime((string)$row['expires_at']) : 0;
        if ($exp > 0 && $exp < time()) return false;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function mh_benefactors_execute_claim(PDO $pdoBio, int $claimId, string $actor): array
{
    $actor = trim($actor);
    if ($claimId < 1 || $actor === '') return ['ok' => false, 'error' => 'invalid_request'];

    if (!mh_benefactors_user_kyc_is_verified($pdoBio, $actor)) {
        return ['ok' => false, 'error' => 'kyc_not_verified'];
    }

    $fee = mh_benefactors_transfer_fee_tokens();
    if ($fee > 0 && function_exists('mh_charge_service_tokens')) {
        $charged = mh_charge_service_tokens($actor, 'benefactors:transfer', 1, ['claim_id' => $claimId], $fee);
        if (!is_array($charged) || (($charged['success'] ?? null) !== true)) {
            return ['ok' => false, 'error' => 'insufficient_tokens_for_fee'];
        }
    }

    $pdoBio->beginTransaction();
    try {
        $stmt = $pdoBio->prepare("SELECT * FROM benefactor_claims WHERE id = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$claimId]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($claim)) {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'claim_not_found'];
        }
        $status = strtolower(trim((string)($claim['status'] ?? 'open')));
        if ($status !== 'open') {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'claim_not_open'];
        }
        $owner = trim((string)($claim['owner_username'] ?? ''));
        if ($owner === '') {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'invalid_owner'];
        }

        $stmt = $pdoBio->prepare("SELECT status, kyc_room_id FROM benefactor_claim_responses WHERE claim_id = ? AND benefactor_username = ? LIMIT 1");
        $stmt->execute([$claimId, $actor]);
        $myRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $myResp = is_array($myRow) ? strtolower(trim((string)($myRow['status'] ?? ''))) : '';
        $myRoomId = is_array($myRow) ? trim((string)($myRow['kyc_room_id'] ?? '')) : '';
        if ($myResp !== 'accepted') {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'not_accepted'];
        }

        if ($myRoomId === '') {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'missing_room_id'];
        }
        $stmt = $pdoBio->prepare("SELECT status, evidence_json FROM user_kyc_sessions WHERE username = ? AND session_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$actor, $myRoomId]);
        $sessRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $sessStatus = is_array($sessRow) ? strtolower(trim((string)($sessRow['status'] ?? ''))) : '';
        $evRaw = is_array($sessRow) ? (string)($sessRow['evidence_json'] ?? '') : '';
        $ev = [];
        if ($evRaw !== '') {
            $d = json_decode($evRaw, true);
            if (is_array($d)) $ev = $d;
        }
        $hashes = [];
        if (is_array($ev) && isset($ev['hashes']) && is_array($ev['hashes'])) {
            $hashes = $ev['hashes'];
        } elseif (is_array($ev)) {
            $hashes = $ev;
        }
        if (empty($hashes['selfie_video.mp4'])) {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'missing_live_video_proof'];
        }
        if ($sessStatus !== 'verified') {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'proof_not_verified'];
        }

        $stmt = $pdoBio->prepare("SELECT benefactor_username, kyc_room_id FROM benefactor_claim_responses WHERE claim_id = ? AND status = 'accepted'");
        $stmt->execute([$claimId]);
        $accepted = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($accepted)) {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'no_accepted_benefactors'];
        }

        $acceptedUsers = [];
        foreach ($accepted as $r) {
            $u = isset($r['benefactor_username']) ? trim((string)$r['benefactor_username']) : '';
            $rid = isset($r['kyc_room_id']) ? trim((string)$r['kyc_room_id']) : '';
            if ($u === '' || $rid === '') continue;
            if (!mh_benefactors_user_kyc_is_verified($pdoBio, $u)) continue;
            $stmt2 = $pdoBio->prepare("SELECT status FROM user_kyc_sessions WHERE username = ? AND session_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmt2->execute([$u, $rid]);
            $st2 = strtolower(trim((string)$stmt2->fetchColumn()));
            if ($st2 !== 'verified') continue;
            $acceptedUsers[] = ['benefactor_username' => $u];
        }
        if (empty($acceptedUsers)) {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'no_verified_benefactors'];
        }

        $stmt = $pdoBio->prepare("SELECT * FROM benefactor_asset_rules WHERE owner_username = ? AND benefactor_username IN (SELECT benefactor_username FROM benefactor_claim_responses WHERE claim_id = ? AND status = 'accepted')");
        $stmt->execute([$owner, $claimId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $snapshot = [];
        $rawSnap = isset($claim['snapshot_json']) ? (string)$claim['snapshot_json'] : '';
        if ($rawSnap !== '') {
            $d = json_decode($rawSnap, true);
            if (is_array($d)) $snapshot = $d;
        }
        if (empty($snapshot)) {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'empty_snapshot'];
        }

        $planned = [];
        foreach ($snapshot as $a) {
            $t = isset($a['type']) ? trim((string)$a['type']) : '';
            $qtyRaw = isset($a['qty']) ? (float)$a['qty'] : 0.0;
            $qty = (int)floor(max(0.0, $qtyRaw));
            if ($t === '' || $qty <= 0) continue;
            $allocPct = mh_benefactors_compute_allocations($acceptedUsers, $rules, $t);
            if (empty($allocPct)) continue;
            $rows = [];
            $used = 0;
            $rema = [];
            foreach ($allocPct as $to => $pct) {
                $exact = $qty * ((float)$pct / 100.0);
                $base = (int)floor($exact);
                if ($base > 0) {
                    $rows[$to] = $base;
                    $used += $base;
                } else {
                    $rows[$to] = 0;
                }
                $rema[$to] = $exact - floor($exact);
            }
            $left = $qty - $used;
            if ($left > 0) {
                arsort($rema);
                foreach ($rema as $to => $fr) {
                    if ($left <= 0) break;
                    $rows[$to] = (int)$rows[$to] + 1;
                    $left--;
                }
            }
            foreach ($rows as $to => $amt) {
                $amt = (int)$amt;
                if ($amt <= 0) continue;
                $planned[] = ['asset_type' => $t, 'to' => $to, 'amount' => $amt];
            }
        }
        if (empty($planned)) {
            $pdoBio->rollBack();
            return ['ok' => false, 'error' => 'no_transfers'];
        }

        $ins = $pdoBio->prepare("INSERT INTO benefactor_claim_transfers (claim_id, asset_type, from_username, to_username, amount_num, status) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($planned as $t) {
            $ins->execute([$claimId, (string)$t['asset_type'], $owner, (string)$t['to'], (string)$t['amount'], 'queued']);
        }

        $pdoBio->commit();

        $applied = [];
        $errors = [];

        foreach ($planned as $t) {
            $assetType = (string)$t['asset_type'];
            $to = (string)$t['to'];
            $amt = (int)$t['amount'];
            if ($amt <= 0) continue;

            if ($assetType === 'utility_token') {
                try {
                    $pdoBio->beginTransaction();
                    $stmt = $pdoBio->prepare("SELECT tokens FROM users WHERE username = ? LIMIT 1 FOR UPDATE");
                    $stmt->execute([$owner]);
                    $ownerTok = (int)$stmt->fetchColumn();
                    $send = min($amt, max(0, $ownerTok));
                    if ($send > 0) {
                        $pdoBio->prepare("UPDATE users SET tokens = GREATEST(tokens - ?, 0) WHERE username = ?")->execute([$send, $owner]);
                        $pdoBio->prepare("UPDATE users SET tokens = tokens + ? WHERE username = ?")->execute([$send, $to]);
                        $pdoBio->prepare("UPDATE benefactor_claim_transfers SET status = 'done' WHERE claim_id = ? AND asset_type = ? AND to_username = ?")->execute([$claimId, $assetType, $to]);
                        $applied[] = ['asset_type' => $assetType, 'to' => $to, 'amount' => $send];
                    } else {
                        $pdoBio->prepare("UPDATE benefactor_claim_transfers SET status = 'skipped' WHERE claim_id = ? AND asset_type = ? AND to_username = ?")->execute([$claimId, $assetType, $to]);
                    }
                    $pdoBio->commit();
                } catch (Throwable $e) {
                    if ($pdoBio->inTransaction()) $pdoBio->rollBack();
                    $errors[] = $assetType . ':' . $to;
                }
                continue;
            }

            if (str_starts_with($assetType, 'equity_')) {
                try {
                    $ok = mh_benefactors_transfer_equity_units($owner, $to, $amt, $assetType);
                    if ($ok) {
                        $pdoBio->prepare("UPDATE benefactor_claim_transfers SET status = 'done' WHERE claim_id = ? AND asset_type = ? AND to_username = ?")->execute([$claimId, $assetType, $to]);
                        $applied[] = ['asset_type' => $assetType, 'to' => $to, 'amount' => $amt];
                    } else {
                        $pdoBio->prepare("UPDATE benefactor_claim_transfers SET status = 'skipped' WHERE claim_id = ? AND asset_type = ? AND to_username = ?")->execute([$claimId, $assetType, $to]);
                    }
                } catch (Throwable $e) {
                    $errors[] = $assetType . ':' . $to;
                }
                continue;
            }
        }

        try {
            $pdoBio->prepare("UPDATE benefactor_claims SET status = 'executed' WHERE id = ?")->execute([$claimId]);
        } catch (Throwable $e) {}

        return ['ok' => true, 'status' => 'executed', 'applied' => $applied, 'errors' => $errors];
    } catch (Throwable $e) {
        if ($pdoBio->inTransaction()) $pdoBio->rollBack();
        return ['ok' => false, 'error' => 'execute_failed'];
    }
}

function mh_benefactors_transfer_equity_units(string $owner, string $to, int $units, string $assetType): bool
{
    $owner = trim($owner);
    $to = trim($to);
    if ($owner === '' || $to === '' || $owner === $to || $units < 1) return false;
    $pdo = getEquityConnection();
    if (!$pdo instanceof PDO) return false;
    mh_equity_ensure_schema($pdo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $filter = function(string $className) use ($assetType): bool {
        $cn = strtolower(trim($className));
        if ($assetType === 'equity_coins') return true;
        if ($assetType === 'equity_ordinary_coins') return (strpos($cn, 'ordinary') !== false || strpos($cn, 'common') !== false);
        if ($assetType === 'equity_preference_coins') return (strpos($cn, 'preference') !== false || strpos($cn, 'preferred') !== false);
        return true;
    };

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT l.class_id, c.name, l.units_owned
            FROM equity_ledger l JOIN equity_classes c ON c.id = l.class_id
            WHERE l.username = ? AND l.units_owned > 0
            ORDER BY l.class_id ASC
            FOR UPDATE");
        $stmt->execute([$owner]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $eligible = [];
        $total = 0;
        foreach ($rows as $r) {
            $cn = (string)($r['name'] ?? '');
            if (!$filter($cn)) continue;
            $u = (int)($r['units_owned'] ?? 0);
            if ($u < 1) continue;
            $eligible[] = ['class_id' => (int)$r['class_id'], 'units' => $u];
            $total += $u;
        }
        if ($total < 1) {
            $pdo->commit();
            return false;
        }
        $send = min($units, $total);

        $updOwner = $pdo->prepare("UPDATE equity_ledger SET units_owned = units_owned - ? WHERE username = ? AND class_id = ?");
        $insTo = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");

        $remaining = $send;
        foreach ($eligible as $e) {
            if ($remaining <= 0) break;
            $classId = (int)$e['class_id'];
            $have = (int)$e['units'];
            $take = min($have, $remaining);
            if ($take <= 0) continue;
            $updOwner->execute([$take, $owner, $classId]);
            $insTo->execute([$to, $classId, $take, $take]);
            $remaining -= $take;
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}
