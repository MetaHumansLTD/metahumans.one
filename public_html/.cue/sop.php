<?php

function sop_get_context(): array {
    $username = isset($_SESSION['mh_auth_user']) && is_string($_SESSION['mh_auth_user']) ? trim($_SESSION['mh_auth_user']) : '';
    $tenantId = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim($_SESSION['mh_tenant_id']) : '';
    if ($tenantId === '' && $username !== '') {
        $tenantId = 'user:' . $username;
    }

    $personaId = isset($_SESSION['mh_auth_persona']) && is_string($_SESSION['mh_auth_persona']) ? trim($_SESSION['mh_auth_persona']) : '';
    $metaHumanId = isset($_SESSION['mh_meta_human_id']) && is_string($_SESSION['mh_meta_human_id']) ? trim($_SESSION['mh_meta_human_id']) : '';
    if ($metaHumanId === '' && $personaId !== '') {
        $metaHumanId = $personaId;
    }

    $principalId = $username !== '' ? ('user:' . $username) : '';
    return [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'principal_id' => $principalId,
        'username' => $username,
    ];
}

function sop_get_pdo(): PDO {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (function_exists('database_getContextAwareConnection')) {
        return database_getContextAwareConnection();
    }
    return cue_autoload('database')->getContextAwareConnection();
}

function sop_random_id(string $prefix): string {
    $raw = random_bytes(12);
    $id = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $prefix . '-' . strtoupper($id);
}

function sop_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_sops (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        sop_id VARCHAR(64) NOT NULL,
        version VARCHAR(32) NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        scope TEXT NULL,
        policies_json JSON NULL,
        attachments_json JSON NULL,
        token_policy_json JSON NULL,
        default_verification_policy_json JSON NULL,
        status ENUM('draft','submitted','authorized','deprecated','archived') NOT NULL DEFAULT 'draft',
        supersedes_sop_id VARCHAR(64) NULL,
        supersedes_version VARCHAR(32) NULL,
        created_by_principal_id VARCHAR(255) NOT NULL,
        created_by_username VARCHAR(255) NULL,
        approved_by_principal_id VARCHAR(255) NULL,
        approved_by_username VARCHAR(255) NULL,
        approved_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_sop_version (tenant_id, sop_id, version),
        KEY idx_tenant_status_time (tenant_id, status, created_at),
        KEY idx_tenant_title (tenant_id, title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_sop_actions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        sop_id VARCHAR(64) NOT NULL,
        sop_version VARCHAR(32) NOT NULL,
        step_number INT NOT NULL,
        step_name VARCHAR(255) NOT NULL,
        required_role VARCHAR(64) NOT NULL DEFAULT '',
        actor_type_allowed ENUM('human','machine','either') NOT NULL DEFAULT 'either',
        instructions_text TEXT NULL,
        required_evidence_min INT NOT NULL DEFAULT 0,
        verifier_policy_json JSON NULL,
        completion_criteria_text TEXT NULL,
        is_terminal TINYINT(1) NOT NULL DEFAULT 0,
        timeout_seconds INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_step (tenant_id, sop_id, sop_version, step_number),
        KEY idx_sop (tenant_id, sop_id, sop_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_sop_executions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        execution_id VARCHAR(64) NOT NULL,
        sop_id VARCHAR(64) NOT NULL,
        sop_version VARCHAR(32) NOT NULL,
        initiator_principal_id VARCHAR(255) NOT NULL,
        initiator_username VARCHAR(255) NULL,
        status ENUM('created','running','completed','failed','cancelled','closed') NOT NULL DEFAULT 'created',
        current_step_number INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_execution (execution_id),
        KEY idx_tenant_status_time (tenant_id, status, created_at),
        KEY idx_sop (tenant_id, sop_id, sop_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_sop_tasks (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        task_id VARCHAR(64) NOT NULL,
        execution_id VARCHAR(64) NOT NULL,
        sop_id VARCHAR(64) NOT NULL,
        sop_version VARCHAR(32) NOT NULL,
        step_number INT NOT NULL,
        step_name VARCHAR(255) NOT NULL,
        required_role VARCHAR(64) NOT NULL DEFAULT '',
        actor_type ENUM('human','machine') NOT NULL DEFAULT 'human',
        assigned_to_principal_id VARCHAR(255) NULL,
        assigned_to_username VARCHAR(255) NULL,
        status ENUM('created','assigned','in_progress','submitted','verified','accepted','rejected','cancelled') NOT NULL DEFAULT 'created',
        submitted_at DATETIME NULL,
        accepted_at DATETIME NULL,
        accepted_by_principal_id VARCHAR(255) NULL,
        accepted_by_username VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_task (task_id),
        KEY idx_exec_step (execution_id, step_number),
        KEY idx_tenant_assignee (tenant_id, assigned_to_principal_id, status),
        KEY idx_tenant_status_time (tenant_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_task_evidence (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        evidence_id VARCHAR(64) NOT NULL,
        task_id VARCHAR(64) NOT NULL,
        evidence_type VARCHAR(32) NOT NULL,
        uri VARCHAR(1024) NOT NULL,
        sha256 CHAR(64) NOT NULL DEFAULT '',
        provenance_json JSON NULL,
        created_by_principal_id VARCHAR(255) NOT NULL,
        created_by_username VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_evidence (evidence_id),
        KEY idx_task (task_id, created_at),
        KEY idx_tenant_time (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_task_verifier_runs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        verifier_run_id VARCHAR(64) NOT NULL,
        task_id VARCHAR(64) NOT NULL,
        verifier_name VARCHAR(64) NOT NULL,
        status ENUM('pending','pass','fail','error') NOT NULL DEFAULT 'pending',
        report_uri VARCHAR(1024) NULL,
        report_sha256 CHAR(64) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        UNIQUE KEY uniq_verifier_run (verifier_run_id),
        KEY idx_task (task_id, created_at),
        KEY idx_tenant_time (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_task_approvals (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        approval_id VARCHAR(64) NOT NULL,
        task_id VARCHAR(64) NOT NULL,
        principal_id VARCHAR(255) NOT NULL,
        username VARCHAR(255) NULL,
        role VARCHAR(64) NOT NULL DEFAULT '',
        decision ENUM('approve','reject') NOT NULL,
        signature TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_approval (approval_id),
        KEY idx_task (task_id, created_at),
        KEY idx_tenant_time (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_ledger_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        ledger_event_id VARCHAR(64) NOT NULL,
        tenant_id VARCHAR(255) NOT NULL,
        persona_id VARCHAR(255) NOT NULL DEFAULT '',
        meta_human_id VARCHAR(255) NOT NULL DEFAULT '',
        event_type VARCHAR(64) NOT NULL,
        subject_type ENUM('sop','execution','task','work_session','time_slice','settlement_instruction') NOT NULL,
        subject_id VARCHAR(64) NOT NULL,
        payload_json JSON NULL,
        payload_sha256 CHAR(64) NOT NULL,
        prev_event_hash CHAR(64) NULL,
        event_hash CHAR(64) NOT NULL,
        signer_principal_id VARCHAR(255) NULL,
        signer_role VARCHAR(64) NULL,
        signer_signature TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ledger_event (ledger_event_id),
        UNIQUE KEY uniq_event_hash (event_hash),
        KEY idx_tenant_time (tenant_id, created_at),
        KEY idx_subject (subject_type, subject_id, created_at),
        KEY idx_event_type (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function sop_canonical_json($value): string {
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '';
}

function sop_sha256(string $s): string {
    return hash('sha256', $s);
}

function sop_ledger_latest_hash(PDO $pdo, string $tenantId, string $subjectType, string $subjectId): ?string {
    $stmt = $pdo->prepare("SELECT event_hash FROM mh_ledger_events WHERE tenant_id = ? AND subject_type = ? AND subject_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId, $subjectType, $subjectId]);
    $h = $stmt->fetchColumn();
    return is_string($h) && $h !== '' ? $h : null;
}

function sop_ledger_append(PDO $pdo, array $ctx, string $eventType, string $subjectType, string $subjectId, array $payload, ?string $signerRole = null, ?string $signature = null): array {
    $payloadJson = sop_canonical_json($payload);
    $payloadHash = sop_sha256($payloadJson);
    $prev = sop_ledger_latest_hash($pdo, (string)$ctx['tenant_id'], $subjectType, $subjectId);
    $ts = gmdate('Y-m-d H:i:s');
    $eventHash = sop_sha256(($prev ?? '') . $payloadHash . $eventType . $subjectType . $subjectId . $ts);
    $ledgerEventId = sop_random_id('LED');

    $stmt = $pdo->prepare("INSERT INTO mh_ledger_events
        (ledger_event_id, tenant_id, persona_id, meta_human_id, event_type, subject_type, subject_id, payload_json, payload_sha256, prev_event_hash, event_hash, signer_principal_id, signer_role, signer_signature, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $ledgerEventId,
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $eventType,
        $subjectType,
        $subjectId,
        $payloadJson !== '' ? $payloadJson : null,
        $payloadHash,
        $prev,
        $eventHash,
        (string)$ctx['principal_id'],
        $signerRole,
        $signature,
        $ts,
    ]);

    return ['ledger_event_id' => $ledgerEventId, 'event_hash' => $eventHash, 'prev_event_hash' => $prev];
}

function sop_is_director(): bool {
    $role = isset($_SESSION['mh_auth_role']) && is_string($_SESSION['mh_auth_role']) ? strtolower(trim($_SESSION['mh_auth_role'])) : '';
    if ($role === '') return false;
    return str_contains($role, 'director') || str_contains($role, 'kripz');
}

function sop_create_sop(PDO $pdo, array $ctx, string $title, string $description, string $scope): array {
    $sopId = sop_random_id('SOP');
    $version = '1.0.0';
    $stmt = $pdo->prepare("INSERT INTO mh_sops
        (tenant_id, persona_id, meta_human_id, sop_id, version, title, description, scope, status, created_by_principal_id, created_by_username)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?)");
    $stmt->execute([
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $sopId,
        $version,
        $title,
        $description !== '' ? $description : null,
        $scope !== '' ? $scope : null,
        (string)$ctx['principal_id'],
        (string)$ctx['username'],
    ]);
    sop_ledger_append($pdo, $ctx, 'sop.created', 'sop', $sopId . '@' . $version, ['title' => $title, 'version' => $version, 'status' => 'draft']);
    return ['sop_id' => $sopId, 'version' => $version];
}

function sop_get_sop(PDO $pdo, string $tenantId, string $sopId, string $version): ?array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sops WHERE tenant_id = ? AND sop_id = ? AND version = ? LIMIT 1");
    $stmt->execute([$tenantId, $sopId, $version]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function sop_list_sops(PDO $pdo, string $tenantId, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("SELECT sop_id, version, title, status, created_at, approved_at FROM mh_sops WHERE tenant_id = ? ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sop_list_actions(PDO $pdo, string $tenantId, string $sopId, string $version): array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sop_actions WHERE tenant_id = ? AND sop_id = ? AND sop_version = ? ORDER BY step_number ASC");
    $stmt->execute([$tenantId, $sopId, $version]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sop_add_action(PDO $pdo, array $ctx, string $sopId, string $version, int $stepNumber, string $stepName, string $requiredRole, string $actorTypeAllowed, int $requiredEvidenceMin, bool $isTerminal, ?array $verifierPolicy = null): void {
    $verifierPolicyJson = null;
    if (is_array($verifierPolicy)) {
        $encoded = json_encode($verifierPolicy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) && $encoded !== '' && $encoded !== 'null') {
            $verifierPolicyJson = $encoded;
        }
    }
    $stmt = $pdo->prepare("INSERT INTO mh_sop_actions
        (tenant_id, persona_id, meta_human_id, sop_id, sop_version, step_number, step_name, required_role, actor_type_allowed, required_evidence_min, verifier_policy_json, is_terminal)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE step_name = VALUES(step_name), required_role = VALUES(required_role), actor_type_allowed = VALUES(actor_type_allowed), required_evidence_min = VALUES(required_evidence_min), verifier_policy_json = VALUES(verifier_policy_json), is_terminal = VALUES(is_terminal)");
    $stmt->execute([
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $sopId,
        $version,
        $stepNumber,
        $stepName,
        $requiredRole,
        in_array($actorTypeAllowed, ['human', 'machine', 'either'], true) ? $actorTypeAllowed : 'either',
        max(0, min(50, $requiredEvidenceMin)),
        $verifierPolicyJson,
        $isTerminal ? 1 : 0,
    ]);
    sop_ledger_append($pdo, $ctx, 'sop.action.upsert', 'sop', $sopId . '@' . $version, ['step_number' => $stepNumber, 'step_name' => $stepName, 'required_role' => $requiredRole, 'actor' => $actorTypeAllowed, 'terminal' => $isTerminal]);
}

function sop_set_status(PDO $pdo, array $ctx, string $sopId, string $version, string $status): void {
    $allowed = ['draft', 'submitted', 'authorized', 'deprecated', 'archived'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('invalid_status');
    }
    $stmt = $pdo->prepare("UPDATE mh_sops SET status = ?, approved_at = CASE WHEN ? = 'authorized' THEN COALESCE(approved_at, NOW()) ELSE approved_at END,
        approved_by_principal_id = CASE WHEN ? = 'authorized' THEN COALESCE(approved_by_principal_id, ?) ELSE approved_by_principal_id END,
        approved_by_username = CASE WHEN ? = 'authorized' THEN COALESCE(approved_by_username, ?) ELSE approved_by_username END
        WHERE tenant_id = ? AND sop_id = ? AND version = ?");
    $stmt->execute([
        $status,
        $status,
        $status,
        (string)$ctx['principal_id'],
        $status,
        (string)$ctx['username'],
        (string)$ctx['tenant_id'],
        $sopId,
        $version,
    ]);
    sop_ledger_append($pdo, $ctx, 'sop.status', 'sop', $sopId . '@' . $version, ['status' => $status]);
}

function sop_next_step(PDO $pdo, string $tenantId, string $sopId, string $version, int $currentStep): ?array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sop_actions WHERE tenant_id = ? AND sop_id = ? AND sop_version = ? AND step_number > ? ORDER BY step_number ASC LIMIT 1");
    $stmt->execute([$tenantId, $sopId, $version, $currentStep]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function sop_get_step(PDO $pdo, string $tenantId, string $sopId, string $version, int $stepNumber): ?array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sop_actions WHERE tenant_id = ? AND sop_id = ? AND sop_version = ? AND step_number = ? LIMIT 1");
    $stmt->execute([$tenantId, $sopId, $version, $stepNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function sop_pick_assignee(PDO $pdo, string $tenantId, string $requiredRole): ?array {
    $requiredRole = strtolower(trim($requiredRole));
    if ($requiredRole === '') return null;
    $stmt = $pdo->prepare("SELECT username, role, tenant_id FROM users WHERE tenant_id = ? AND role IS NOT NULL AND LOWER(role) LIKE ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$tenantId, '%' . $requiredRole . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $u = isset($row['username']) ? trim((string)$row['username']) : '';
    if ($u === '') return null;
    return ['principal_id' => 'user:' . $u, 'username' => $u];
}

function sop_create_execution(PDO $pdo, array $ctx, string $sopId, string $version): array {
    $executionId = sop_random_id('EXEC');
    $stmt = $pdo->prepare("INSERT INTO mh_sop_executions
        (tenant_id, persona_id, meta_human_id, execution_id, sop_id, sop_version, initiator_principal_id, initiator_username, status, current_step_number)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'running', 0)");
    $stmt->execute([
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $executionId,
        $sopId,
        $version,
        (string)$ctx['principal_id'],
        (string)$ctx['username'],
    ]);
    sop_ledger_append($pdo, $ctx, 'execution.created', 'execution', $executionId, ['sop_id' => $sopId, 'version' => $version, 'status' => 'running']);
    return ['execution_id' => $executionId];
}

function sop_list_executions(PDO $pdo, string $tenantId, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("SELECT execution_id, sop_id, sop_version, status, current_step_number, created_at, updated_at FROM mh_sop_executions WHERE tenant_id = ? ORDER BY created_at DESC LIMIT {$limit}");
    $stmt->execute([$tenantId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sop_get_execution(PDO $pdo, string $tenantId, string $executionId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sop_executions WHERE tenant_id = ? AND execution_id = ? LIMIT 1");
    $stmt->execute([$tenantId, $executionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function sop_list_tasks_for_execution(PDO $pdo, string $tenantId, string $executionId): array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sop_tasks WHERE tenant_id = ? AND execution_id = ? ORDER BY step_number ASC");
    $stmt->execute([$tenantId, $executionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sop_get_task(PDO $pdo, string $tenantId, string $taskId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM mh_sop_tasks WHERE tenant_id = ? AND task_id = ? LIMIT 1");
    $stmt->execute([$tenantId, $taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function sop_create_task(PDO $pdo, array $ctx, string $executionId, string $sopId, string $version, array $step): array {
    $taskId = sop_random_id('TASK');
    $stepNumber = (int)($step['step_number'] ?? 0);
    $stepName = (string)($step['step_name'] ?? '');
    $requiredRole = (string)($step['required_role'] ?? '');
    $actorAllowed = (string)($step['actor_type_allowed'] ?? 'either');
    $actorType = $actorAllowed === 'machine' ? 'machine' : 'human';

    $assignee = null;
    if ($requiredRole !== '') {
        try {
            $assignee = sop_pick_assignee($pdo, (string)$ctx['tenant_id'], $requiredRole);
        } catch (Throwable $e) {
            $assignee = null;
        }
    }
    if (!$assignee && (string)$ctx['principal_id'] !== '') {
        $assignee = ['principal_id' => (string)$ctx['principal_id'], 'username' => (string)$ctx['username']];
    }

    $stmt = $pdo->prepare("INSERT INTO mh_sop_tasks
        (tenant_id, persona_id, meta_human_id, task_id, execution_id, sop_id, sop_version, step_number, step_name, required_role, actor_type, assigned_to_principal_id, assigned_to_username, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'assigned')");
    $stmt->execute([
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $taskId,
        $executionId,
        $sopId,
        $version,
        $stepNumber,
        $stepName,
        $requiredRole,
        $actorType,
        $assignee ? (string)$assignee['principal_id'] : null,
        $assignee ? (string)$assignee['username'] : null,
    ]);

    $payload = ['execution_id' => $executionId, 'sop_id' => $sopId, 'version' => $version, 'step_number' => $stepNumber, 'step_name' => $stepName, 'assigned_to' => $assignee ? (string)$assignee['principal_id'] : null];
    sop_ledger_append($pdo, $ctx, 'task.created', 'task', $taskId, $payload);
    sop_ledger_append($pdo, $ctx, 'task.assigned', 'task', $taskId, $payload);
    return ['task_id' => $taskId];
}

function sop_set_task_status(PDO $pdo, array $ctx, string $taskId, string $status): void {
    $allowed = ['assigned', 'in_progress', 'submitted', 'verified', 'accepted', 'rejected', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('invalid_task_status');
    }

    $now = gmdate('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE mh_sop_tasks SET status = ?,
        submitted_at = CASE WHEN ? = 'submitted' THEN COALESCE(submitted_at, ?) ELSE submitted_at END,
        accepted_at = CASE WHEN ? = 'accepted' THEN COALESCE(accepted_at, ?) ELSE accepted_at END,
        accepted_by_principal_id = CASE WHEN ? = 'accepted' THEN COALESCE(accepted_by_principal_id, ?) ELSE accepted_by_principal_id END,
        accepted_by_username = CASE WHEN ? = 'accepted' THEN COALESCE(accepted_by_username, ?) ELSE accepted_by_username END
        WHERE tenant_id = ? AND task_id = ?");
    $stmt->execute([
        $status,
        $status,
        $now,
        $status,
        $now,
        $status,
        (string)$ctx['principal_id'],
        $status,
        (string)$ctx['username'],
        (string)$ctx['tenant_id'],
        $taskId,
    ]);

    sop_ledger_append($pdo, $ctx, 'task.status', 'task', $taskId, ['status' => $status]);
}

function sop_count_task_evidence(PDO $pdo, string $tenantId, string $taskId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mh_task_evidence WHERE tenant_id = ? AND task_id = ?");
    $stmt->execute([$tenantId, $taskId]);
    return (int)$stmt->fetchColumn();
}

function sop_add_evidence(PDO $pdo, array $ctx, string $taskId, string $evidenceType, string $uri, string $sha256, ?array $provenance): array {
    $evidenceId = sop_random_id('EVD');
    $stmt = $pdo->prepare("INSERT INTO mh_task_evidence
        (tenant_id, persona_id, meta_human_id, evidence_id, task_id, evidence_type, uri, sha256, provenance_json, created_by_principal_id, created_by_username)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $evidenceId,
        $taskId,
        $evidenceType,
        $uri,
        $sha256,
        $provenance ? sop_canonical_json($provenance) : null,
        (string)$ctx['principal_id'],
        (string)$ctx['username'],
    ]);
    sop_ledger_append($pdo, $ctx, 'evidence.added', 'task', $taskId, ['evidence_id' => $evidenceId, 'type' => $evidenceType, 'uri' => $uri, 'sha256' => $sha256]);
    return ['evidence_id' => $evidenceId];
}

function sop_list_evidence(PDO $pdo, string $tenantId, string $taskId): array {
    $stmt = $pdo->prepare("SELECT * FROM mh_task_evidence WHERE tenant_id = ? AND task_id = ? ORDER BY created_at ASC");
    $stmt->execute([$tenantId, $taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sop_run_verifiers(PDO $pdo, array $ctx, array $task, array $step): array {
    $taskId = (string)($task['task_id'] ?? '');
    $tenantId = (string)$ctx['tenant_id'];
    $policyJson = isset($step['verifier_policy_json']) ? (string)$step['verifier_policy_json'] : '';
    $policy = $policyJson !== '' ? json_decode($policyJson, true) : [];
    $policy = is_array($policy) ? $policy : [];

    $verifiers = [];
    if (isset($policy['verifiers']) && is_array($policy['verifiers'])) {
        foreach ($policy['verifiers'] as $v) {
            if (is_string($v) && trim($v) !== '') {
                $verifiers[] = trim($v);
            }
        }
    }
    if (empty($verifiers)) {
        $verifiers = ['evidence_min'];
    }

    $results = [];
    $allOk = true;

    $evidenceRows = sop_list_evidence($pdo, $tenantId, $taskId);

    $runStore = function (string $name, string $status, ?string $reportUri = null, ?string $reportSha = null, ?array $meta = null) use ($pdo, $ctx, $taskId): void {
        $runId = sop_random_id('VRF');
        $stmt = $pdo->prepare("INSERT INTO mh_task_verifier_runs
            (tenant_id, persona_id, meta_human_id, verifier_run_id, task_id, verifier_name, status, report_uri, report_sha256, created_at, finished_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            (string)$ctx['tenant_id'],
            (string)$ctx['persona_id'],
            (string)$ctx['meta_human_id'],
            $runId,
            $taskId,
            $name,
            $status,
            $reportUri,
            $reportSha,
        ]);
        $payload = ['verifier' => $name, 'status' => $status];
        if (is_array($meta)) {
            $payload['meta'] = $meta;
        }
        sop_ledger_append($pdo, $ctx, 'verifier.run', 'task', $taskId, $payload);
    };

    $localFileFromUri = function (string $uri): ?string {
        if ($uri === '') return null;
        if (str_starts_with($uri, 'file://')) {
            $uri = substr($uri, 7);
        }
        if (!str_starts_with($uri, '/')) return null;
        $real = realpath($uri);
        if (!is_string($real) || $real === '') return null;
        $allowed = [
            '/home/onemeta/public_html',
            '/home/onemeta/.data',
            '/data',
            '/tmp',
        ];
        foreach ($allowed as $base) {
            if (strncmp($real, $base, strlen($base)) === 0) {
                return $real;
            }
        }
        return null;
    };

    $httpHeadOk = function (string $uri): bool {
        if (!preg_match('~^https?://~i', $uri)) return false;
        $ctx = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 2,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);
        $headers = @get_headers($uri, true, $ctx);
        if (!is_array($headers) || empty($headers[0]) || !is_string($headers[0])) return false;
        return preg_match('~\\s(200|204|206)\\s~', $headers[0]) === 1;
    };

    foreach ($verifiers as $v) {
        $name = strtolower($v);
        $ok = false;
        $meta = [];

        if ($name === 'evidence_min') {
            $requiredEvidenceMin = (int)($step['required_evidence_min'] ?? 0);
            $evCount = count($evidenceRows);
            $ok = $evCount >= $requiredEvidenceMin;
            $meta = ['evidence_count' => $evCount, 'required_min' => $requiredEvidenceMin];
            $runStore('evidence_min', $ok ? 'pass' : 'fail', null, null, $meta);
        } elseif ($name === 'uri_exists') {
            $ok = false;
            foreach ($evidenceRows as $ev) {
                $uri = isset($ev['uri']) ? (string)$ev['uri'] : '';
                if ($uri === '') continue;
                $local = $localFileFromUri($uri);
                if ($local && is_file($local)) {
                    $ok = true;
                    $meta = ['matched_uri' => $uri];
                    break;
                }
                if ($httpHeadOk($uri)) {
                    $ok = true;
                    $meta = ['matched_uri' => $uri];
                    break;
                }
            }
            $runStore('uri_exists', $ok ? 'pass' : 'fail', null, null, $meta);
        } elseif ($name === 'sha256_matches') {
            $ok = false;
            foreach ($evidenceRows as $ev) {
                $uri = isset($ev['uri']) ? (string)$ev['uri'] : '';
                $sha = isset($ev['sha256']) ? strtolower((string)$ev['sha256']) : '';
                if ($uri === '' || $sha === '' || !preg_match('/^[a-f0-9]{64}$/', $sha)) continue;
                $local = $localFileFromUri($uri);
                if (!$local || !is_file($local)) continue;
                $hash = @hash_file('sha256', $local);
                if (is_string($hash) && hash_equals($sha, strtolower($hash))) {
                    $ok = true;
                    $meta = ['matched_uri' => $uri];
                    break;
                }
            }
            $runStore('sha256_matches', $ok ? 'pass' : 'fail', null, null, $meta);
        } elseif ($name === 'pdf_valid') {
            $ok = false;
            foreach ($evidenceRows as $ev) {
                $uri = isset($ev['uri']) ? (string)$ev['uri'] : '';
                if ($uri === '') continue;
                $local = $localFileFromUri($uri);
                if (!$local || !is_file($local)) continue;
                $head = @file_get_contents($local, false, null, 0, 8);
                if (!is_string($head) || strncmp($head, '%PDF-', 5) !== 0) continue;
                $size = @filesize($local);
                $size = is_int($size) ? $size : 0;
                $tailLen = $size > 4096 ? 4096 : $size;
                if ($tailLen <= 0) continue;
                $tail = @file_get_contents($local, false, null, max(0, $size - $tailLen), $tailLen);
                if (!is_string($tail)) continue;
                if (strpos($tail, '%%EOF') !== false) {
                    $ok = true;
                    $meta = ['matched_uri' => $uri];
                    break;
                }
            }
            $runStore('pdf_valid', $ok ? 'pass' : 'fail', null, null, $meta);
        } elseif ($name === 'ci_passed') {
            $ok = false;
            foreach ($evidenceRows as $ev) {
                $type = isset($ev['evidence_type']) ? strtolower((string)$ev['evidence_type']) : '';
                if ($type !== 'ci_report' && $type !== 'verifier_report') continue;
                $provJson = isset($ev['provenance_json']) ? (string)$ev['provenance_json'] : '';
                $prov = $provJson !== '' ? json_decode($provJson, true) : [];
                $prov = is_array($prov) ? $prov : [];
                $status = isset($prov['status']) ? strtolower((string)$prov['status']) : '';
                if ($status === 'pass' || $status === 'passed' || $status === 'success') {
                    $ok = true;
                    $meta = ['matched_evidence_id' => (string)($ev['evidence_id'] ?? '')];
                    break;
                }
            }
            $runStore('ci_passed', $ok ? 'pass' : 'fail', null, null, $meta);
        } else {
            $runStore($name, 'error', null, null, ['error' => 'unknown_verifier']);
            $ok = false;
        }

        $results[] = ['verifier' => $name, 'success' => $ok, 'meta' => $meta];
        if (!$ok) {
            $allOk = false;
        }
    }

    return ['success' => $allOk, 'results' => $results];
}

function sop_add_approval(PDO $pdo, array $ctx, string $taskId, string $role, string $decision, ?string $signature = null): array {
    $approvalId = sop_random_id('APR');
    $stmt = $pdo->prepare("INSERT INTO mh_task_approvals
        (tenant_id, persona_id, meta_human_id, approval_id, task_id, principal_id, username, role, decision, signature)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (string)$ctx['tenant_id'],
        (string)$ctx['persona_id'],
        (string)$ctx['meta_human_id'],
        $approvalId,
        $taskId,
        (string)$ctx['principal_id'],
        (string)$ctx['username'],
        $role,
        $decision,
        $signature,
    ]);
    sop_ledger_append($pdo, $ctx, 'approval.added', 'task', $taskId, ['approval_id' => $approvalId, 'role' => $role, 'decision' => $decision]);
    return ['approval_id' => $approvalId];
}

function sop_list_approvals(PDO $pdo, string $tenantId, string $taskId): array {
    $stmt = $pdo->prepare("SELECT * FROM mh_task_approvals WHERE tenant_id = ? AND task_id = ? ORDER BY created_at ASC");
    $stmt->execute([$tenantId, $taskId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function sop_required_approvals_from_step(array $step): array {
    $policyJson = isset($step['verifier_policy_json']) ? (string)$step['verifier_policy_json'] : '';
    $policy = $policyJson !== '' ? json_decode($policyJson, true) : [];
    $policy = is_array($policy) ? $policy : [];
    $ap = isset($policy['approvals']) && is_array($policy['approvals']) ? $policy['approvals'] : [];
    $roles = [];
    if (isset($ap['roles']) && is_array($ap['roles'])) {
        foreach ($ap['roles'] as $r) {
            if (is_string($r) && trim($r) !== '') {
                $roles[] = strtolower(trim($r));
            }
        }
    }
    $quorum = isset($ap['quorum']) ? (int)$ap['quorum'] : 0;
    $required = isset($ap['required']) ? (bool)$ap['required'] : false;
    if (!$required || $quorum <= 0 || empty($roles)) {
        return ['required' => false, 'roles' => [], 'quorum' => 0];
    }
    return ['required' => true, 'roles' => array_values(array_unique($roles)), 'quorum' => $quorum];
}

function sop_is_client_for_execution(array $ctx, array $exec): bool {
    $pid = isset($ctx['principal_id']) ? (string)$ctx['principal_id'] : '';
    $init = isset($exec['initiator_principal_id']) ? (string)$exec['initiator_principal_id'] : '';
    return $pid !== '' && $init !== '' && hash_equals($init, $pid);
}

function sop_approvals_satisfied(array $required, array $approvals, array $ctx, array $exec): bool {
    if (!($required['required'] ?? false)) return true;
    $roles = isset($required['roles']) && is_array($required['roles']) ? $required['roles'] : [];
    $quorum = (int)($required['quorum'] ?? 0);
    if ($quorum <= 0 || empty($roles)) return true;

    $count = 0;
    foreach ($approvals as $ap) {
        $dec = isset($ap['decision']) ? strtolower((string)$ap['decision']) : '';
        if ($dec !== 'approve') continue;
        $r = isset($ap['role']) ? strtolower((string)$ap['role']) : '';
        if ($r === '') continue;
        if (!in_array($r, $roles, true)) continue;
        $count++;
    }
    if ($count >= $quorum) return true;

    if (in_array('director', $roles, true) && function_exists('sop_is_director') && sop_is_director()) {
        return $quorum <= 1;
    }
    if (in_array('client', $roles, true) && sop_is_client_for_execution($ctx, $exec)) {
        return $quorum <= 1;
    }
    return false;
}

function sop_auto_advance_execution(PDO $pdo, array $ctx, array $task): ?array {
    $executionId = (string)($task['execution_id'] ?? '');
    if ($executionId === '') return null;
    $exec = sop_get_execution($pdo, (string)$ctx['tenant_id'], $executionId);
    if (!$exec) return null;

    $sopId = (string)($exec['sop_id'] ?? '');
    $version = (string)($exec['sop_version'] ?? '');
    $stepNumber = (int)($task['step_number'] ?? 0);
    $step = sop_get_step($pdo, (string)$ctx['tenant_id'], $sopId, $version, $stepNumber);
    $isTerminal = $step ? ((int)($step['is_terminal'] ?? 0) === 1) : false;

    $stmt = $pdo->prepare("UPDATE mh_sop_executions SET current_step_number = ?, status = CASE WHEN ? THEN 'completed' ELSE status END WHERE tenant_id = ? AND execution_id = ?");
    $stmt->execute([$stepNumber, $isTerminal ? 1 : 0, (string)$ctx['tenant_id'], $executionId]);

    if ($isTerminal) {
        sop_ledger_append($pdo, $ctx, 'execution.completed', 'execution', $executionId, ['step_number' => $stepNumber]);
        return ['execution_id' => $executionId, 'completed' => true];
    }

    $next = sop_next_step($pdo, (string)$ctx['tenant_id'], $sopId, $version, $stepNumber);
    if (!$next) {
        sop_ledger_append($pdo, $ctx, 'execution.completed', 'execution', $executionId, ['step_number' => $stepNumber]);
        $pdo->prepare("UPDATE mh_sop_executions SET status = 'completed' WHERE tenant_id = ? AND execution_id = ?")->execute([(string)$ctx['tenant_id'], $executionId]);
        return ['execution_id' => $executionId, 'completed' => true];
    }

    $created = sop_create_task($pdo, $ctx, $executionId, $sopId, $version, $next);
    return ['execution_id' => $executionId, 'completed' => false, 'next_task_id' => $created['task_id'] ?? null];
}
