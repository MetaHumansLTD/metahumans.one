<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
require_once dirname(__DIR__, 2) . '/.cue/cue.php';

if (function_exists('cue_autoload')) {
    cue_autoload('security');
    cue_autoload('sop');
}

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim($_SESSION['mh_auth_user']) === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized', 'redirect' => '/auth/login.php']);
    exit;
}

$security = function_exists('cue_autoload') ? cue_autoload('security') : null;
$csrfToken = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
$action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
if ($action !== 'csrf' && (!$security || !$security->validateCSRFToken($csrfToken, 'sop'))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_action']);
    exit;
}

if ($action === 'csrf') {
    $token = $security ? $security->generateCSRFToken('sop') : '';
    echo json_encode(['success' => true, 'csrf_token' => $token]);
    exit;
}

$ctx = function_exists('sop_get_context') ? sop_get_context() : [];
if (!is_array($ctx) || !isset($ctx['tenant_id']) || !is_string($ctx['tenant_id']) || $ctx['tenant_id'] === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_tenant']);
    exit;
}

try {
    $pdo = sop_get_pdo();
    sop_ensure_schema($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_unavailable']);
    exit;
}

try {
    if ($action === 'sop_create') {
        if (!function_exists('sop_is_director') || !sop_is_director()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
        $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
        $scope = isset($_POST['scope']) ? trim((string)$_POST['scope']) : '';
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'missing_title']);
            exit;
        }
        $res = sop_create_sop($pdo, $ctx, $title, $description, $scope);
        echo json_encode(['success' => true, 'sop_id' => $res['sop_id'] ?? '', 'version' => $res['version'] ?? '']);
        exit;
    }

    if ($action === 'sop_action_upsert') {
        if (!function_exists('sop_is_director') || !sop_is_director()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        $sopId = isset($_POST['sop_id']) ? trim((string)$_POST['sop_id']) : '';
        $version = isset($_POST['version']) ? trim((string)$_POST['version']) : '';
        $stepNumber = isset($_POST['step_number']) ? (int)$_POST['step_number'] : 0;
        $stepName = isset($_POST['step_name']) ? trim((string)$_POST['step_name']) : '';
        $requiredRole = isset($_POST['required_role']) ? trim((string)$_POST['required_role']) : '';
        $actorAllowed = isset($_POST['actor_type_allowed']) ? trim((string)$_POST['actor_type_allowed']) : 'either';
        $requiredEvidenceMin = isset($_POST['required_evidence_min']) ? (int)$_POST['required_evidence_min'] : 0;
        $isTerminal = isset($_POST['is_terminal']) ? (string)$_POST['is_terminal'] : '';
        $verifiersCsv = isset($_POST['verifiers']) ? trim((string)$_POST['verifiers']) : '';
        $approvalRequired = isset($_POST['approval_required']) ? (string)$_POST['approval_required'] : '';
        $approvalRolesCsv = isset($_POST['approval_roles']) ? trim((string)$_POST['approval_roles']) : '';
        $approvalQuorum = isset($_POST['approval_quorum']) ? (int)$_POST['approval_quorum'] : 0;

        if ($sopId === '' || $version === '' || $stepNumber < 1 || $stepName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }

        $sop = sop_get_sop($pdo, (string)$ctx['tenant_id'], $sopId, $version);
        if (!$sop) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        if ((string)($sop['status'] ?? '') !== 'draft') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'not_editable']);
            exit;
        }

        $verifiers = [];
        if ($verifiersCsv !== '') {
            foreach (preg_split('/[,\\s]+/', $verifiersCsv) as $v) {
                $v = trim((string)$v);
                if ($v !== '') $verifiers[] = $v;
            }
        }
        $verifiers = array_values(array_unique($verifiers));
        $approvalRoles = [];
        if ($approvalRolesCsv !== '') {
            foreach (preg_split('/[,\\s]+/', $approvalRolesCsv) as $r) {
                $r = strtolower(trim((string)$r));
                if ($r !== '') $approvalRoles[] = $r;
            }
        }
        $approvalRoles = array_values(array_unique($approvalRoles));
        $vp = [
            'verifiers' => $verifiers,
            'approvals' => [
                'required' => ($approvalRequired === '1' || strtolower($approvalRequired) === 'true'),
                'roles' => $approvalRoles,
                'quorum' => max(0, min(10, (int)$approvalQuorum)),
            ],
        ];
        sop_add_action($pdo, $ctx, $sopId, $version, $stepNumber, $stepName, $requiredRole, $actorAllowed, $requiredEvidenceMin, $isTerminal === '1' || strtolower($isTerminal) === 'true', $vp);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'sop_submit') {
        if (!function_exists('sop_is_director') || !sop_is_director()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        $sopId = isset($_POST['sop_id']) ? trim((string)$_POST['sop_id']) : '';
        $version = isset($_POST['version']) ? trim((string)$_POST['version']) : '';
        if ($sopId === '' || $version === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $actions = sop_list_actions($pdo, (string)$ctx['tenant_id'], $sopId, $version);
        if (empty($actions)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'no_actions']);
            exit;
        }
        sop_set_status($pdo, $ctx, $sopId, $version, 'submitted');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'sop_authorize') {
        if (!function_exists('sop_is_director') || !sop_is_director()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        $sopId = isset($_POST['sop_id']) ? trim((string)$_POST['sop_id']) : '';
        $version = isset($_POST['version']) ? trim((string)$_POST['version']) : '';
        if ($sopId === '' || $version === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $sop = sop_get_sop($pdo, (string)$ctx['tenant_id'], $sopId, $version);
        if (!$sop) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        if ((string)($sop['status'] ?? '') !== 'submitted') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'not_submitted']);
            exit;
        }
        sop_set_status($pdo, $ctx, $sopId, $version, 'authorized');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'sop_execute') {
        $sopId = isset($_POST['sop_id']) ? trim((string)$_POST['sop_id']) : '';
        $version = isset($_POST['version']) ? trim((string)$_POST['version']) : '';
        if ($sopId === '' || $version === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $sop = sop_get_sop($pdo, (string)$ctx['tenant_id'], $sopId, $version);
        if (!$sop || (string)($sop['status'] ?? '') !== 'authorized') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'not_authorized']);
            exit;
        }
        $first = sop_get_step($pdo, (string)$ctx['tenant_id'], $sopId, $version, 1);
        if (!$first) {
            $actions = sop_list_actions($pdo, (string)$ctx['tenant_id'], $sopId, $version);
            $first = !empty($actions) ? $actions[0] : null;
        }
        if (!$first) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'no_actions']);
            exit;
        }
        $exec = sop_create_execution($pdo, $ctx, $sopId, $version);
        $task = sop_create_task($pdo, $ctx, (string)$exec['execution_id'], $sopId, $version, $first);
        echo json_encode(['success' => true, 'execution_id' => (string)$exec['execution_id'], 'task_id' => (string)($task['task_id'] ?? '')]);
        exit;
    }

    if ($action === 'task_set_status') {
        $taskId = isset($_POST['task_id']) ? trim((string)$_POST['task_id']) : '';
        $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';
        if ($taskId === '' || $status === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $task = sop_get_task($pdo, (string)$ctx['tenant_id'], $taskId);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        $assignee = isset($task['assigned_to_principal_id']) ? (string)$task['assigned_to_principal_id'] : '';
        if ($assignee !== '' && $assignee !== (string)$ctx['principal_id'] && (!function_exists('sop_is_director') || !sop_is_director())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        sop_set_task_status($pdo, $ctx, $taskId, $status);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'task_submit') {
        $taskId = isset($_POST['task_id']) ? trim((string)$_POST['task_id']) : '';
        if ($taskId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $task = sop_get_task($pdo, (string)$ctx['tenant_id'], $taskId);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        $assignee = isset($task['assigned_to_principal_id']) ? (string)$task['assigned_to_principal_id'] : '';
        if ($assignee !== '' && $assignee !== (string)$ctx['principal_id'] && (!function_exists('sop_is_director') || !sop_is_director())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        sop_set_task_status($pdo, $ctx, $taskId, 'submitted');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'task_add_evidence') {
        $taskId = isset($_POST['task_id']) ? trim((string)$_POST['task_id']) : '';
        $evidenceType = isset($_POST['evidence_type']) ? trim((string)$_POST['evidence_type']) : 'artifact';
        $uri = isset($_POST['uri']) ? trim((string)$_POST['uri']) : '';
        $sha256 = isset($_POST['sha256']) ? strtolower(trim((string)$_POST['sha256'])) : '';
        if ($taskId === '' || $uri === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $task = sop_get_task($pdo, (string)$ctx['tenant_id'], $taskId);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        $assignee = isset($task['assigned_to_principal_id']) ? (string)$task['assigned_to_principal_id'] : '';
        if ($assignee !== '' && $assignee !== (string)$ctx['principal_id'] && (!function_exists('sop_is_director') || !sop_is_director())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        if ($sha256 !== '' && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_sha256']);
            exit;
        }
        $res = sop_add_evidence($pdo, $ctx, $taskId, $evidenceType !== '' ? $evidenceType : 'artifact', $uri, $sha256, null);
        echo json_encode(['success' => true, 'evidence_id' => $res['evidence_id'] ?? '']);
        exit;
    }

    if ($action === 'task_approve') {
        $taskId = isset($_POST['task_id']) ? trim((string)$_POST['task_id']) : '';
        $decision = isset($_POST['decision']) ? strtolower(trim((string)$_POST['decision'])) : '';
        $role = isset($_POST['role']) ? strtolower(trim((string)$_POST['role'])) : '';
        if ($taskId === '' || ($decision !== 'approve' && $decision !== 'reject')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $task = sop_get_task($pdo, (string)$ctx['tenant_id'], $taskId);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        $exec = sop_get_execution($pdo, (string)$ctx['tenant_id'], (string)($task['execution_id'] ?? ''));
        if (!$exec) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_execution']);
            exit;
        }
        $sopId = (string)($task['sop_id'] ?? '');
        $version = (string)($task['sop_version'] ?? '');
        $stepNumber = (int)($task['step_number'] ?? 0);
        $step = sop_get_step($pdo, (string)$ctx['tenant_id'], $sopId, $version, $stepNumber);
        if (!$step) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_step']);
            exit;
        }
        $required = sop_required_approvals_from_step($step);
        if (!($required['required'] ?? false)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'approval_not_required']);
            exit;
        }
        $allowedRoles = isset($required['roles']) && is_array($required['roles']) ? $required['roles'] : [];
        if ($role === '') {
            if (function_exists('sop_is_director') && sop_is_director()) $role = 'director';
            elseif (sop_is_client_for_execution($ctx, $exec)) $role = 'client';
        }
        if ($role === '' || !in_array($role, $allowedRoles, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        sop_add_approval($pdo, $ctx, $taskId, $role, $decision, null);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'task_verify_and_accept') {
        $taskId = isset($_POST['task_id']) ? trim((string)$_POST['task_id']) : '';
        if ($taskId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_action']);
            exit;
        }
        $task = sop_get_task($pdo, (string)$ctx['tenant_id'], $taskId);
        if (!$task) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
        $sopId = (string)($task['sop_id'] ?? '');
        $version = (string)($task['sop_version'] ?? '');
        $stepNumber = (int)($task['step_number'] ?? 0);
        $step = sop_get_step($pdo, (string)$ctx['tenant_id'], $sopId, $version, $stepNumber);
        if (!$step) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_step']);
            exit;
        }
        $assignee = isset($task['assigned_to_principal_id']) ? (string)$task['assigned_to_principal_id'] : '';
        if ($assignee !== '' && $assignee !== (string)$ctx['principal_id'] && (!function_exists('sop_is_director') || !sop_is_director())) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'forbidden']);
            exit;
        }
        $vr = sop_run_verifiers($pdo, $ctx, $task, $step);
        if (!($vr['success'] ?? false)) {
            sop_set_task_status($pdo, $ctx, $taskId, 'rejected');
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'verification_failed', 'verifier' => $vr]);
            exit;
        }
        $exec = sop_get_execution($pdo, (string)$ctx['tenant_id'], (string)($task['execution_id'] ?? ''));
        if (!$exec) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'invalid_execution']);
            exit;
        }
        $required = sop_required_approvals_from_step($step);
        if (($required['required'] ?? false)) {
            $approvals = sop_list_approvals($pdo, (string)$ctx['tenant_id'], $taskId);
            if (!sop_approvals_satisfied($required, $approvals, $ctx, $exec)) {
                sop_set_task_status($pdo, $ctx, $taskId, 'verified');
                echo json_encode(['success' => true, 'needs_approval' => true, 'required' => $required]);
                exit;
            }
        }
        sop_set_task_status($pdo, $ctx, $taskId, 'accepted');
        $advance = sop_auto_advance_execution($pdo, $ctx, $task);
        echo json_encode(['success' => true, 'advance' => $advance]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
}
