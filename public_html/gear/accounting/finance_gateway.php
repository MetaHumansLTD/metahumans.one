<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../.cue/sop.php';
require_once __DIR__ . '/../../auth/tenant_provisioning.php';
require_once __DIR__ . '/../grid/grid_db.php';
require_once __DIR__ . '/finance_events.php';

function mh_finance_gateway_actor_context(array $overrides = []): array
{
    $username = trim((string)($overrides['username'] ?? ($_SESSION['mh_auth_user'] ?? '')));
    $role = trim((string)($overrides['role'] ?? ($_SESSION['mh_auth_role'] ?? '')));
    $tenantId = trim((string)($overrides['tenantId'] ?? ''));
    if ($tenantId === '' && $username !== '') {
        $tenantId = str_starts_with($username, 'user:') ? $username : ('user:' . $username);
    }

    $principalId = trim((string)($overrides['principalId'] ?? ''));
    if ($principalId === '') {
        if ($username !== '') {
            $principalId = str_starts_with($username, 'user:') ? $username : ('user:' . $username);
        } else {
            $principalId = 'system:finance_gateway';
        }
    }

    return [
        'tenantId' => $tenantId,
        'principalId' => $principalId,
        'username' => $username,
        'role' => $role,
        'source' => trim((string)($overrides['source'] ?? 'finance_gateway')),
        'personaId' => trim((string)($overrides['personaId'] ?? '')),
        'metaHumanId' => trim((string)($overrides['metaHumanId'] ?? '')),
    ];
}

function mh_finance_gateway_open_db(?string $tenantId = null): PDO
{
    $tenantId = trim((string)$tenantId);
    if ($tenantId !== '' && function_exists('mh_apply_tenant_context')) {
        mh_apply_tenant_context($tenantId);
    }
    $db = null;
    if ($tenantId !== '') {
        $tenantConfigId = $_SESSION['mh_db_preference'] ?? ($_SESSION['current_database_config_id'] ?? null);
        if (is_string($tenantConfigId) && $tenantConfigId !== '' && function_exists('database_getConnectionById')) {
            $candidate = database_getConnectionById($tenantConfigId);
            if ($candidate instanceof PDO) {
                $db = $candidate;
            }
        }
    }
    if (!$db instanceof PDO) {
        $db = mh_grid_get_db();
    }
    if (!$db instanceof PDO) {
        throw new RuntimeException('finance_gateway_db_unavailable');
    }

    mh_finance_ensure_tables($db);
    return $db;
}

function mh_finance_gateway_receipt_path(string $tenantId, string $operation, string $recordedAtUtc): string
{
    $day = substr($recordedAtUtc, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = gmdate('Y-m-d');
    }

    $safeOperation = preg_replace('/[^A-Za-z0-9:_-]/', '_', $operation);
    $safeOperation = trim((string)$safeOperation, '_');
    if ($safeOperation === '') {
        $safeOperation = 'gateway_operation';
    }

    $name = $safeOperation . '-' . gmdate('Ymd-His', strtotime($recordedAtUtc)) . '-' . substr(hash('sha256', $tenantId . '|' . $operation . '|' . $recordedAtUtc), 0, 16) . '.json';
    return mh_finance_tenant_root($tenantId) . '/automation/audit/finance-gateway/' . $day . '/' . $name;
}

function mh_finance_gateway_ensure_sop_schema(PDO $db): void
{
    if (function_exists('sop_ensure_schema')) {
        sop_ensure_schema($db);
        return;
    }
    if (function_exists('sop_ensure_tables')) {
        sop_ensure_tables($db);
        return;
    }
    throw new RuntimeException('finance_gateway_ledger_schema_unavailable');
}

function mh_finance_gateway_anchor_receipt(PDO $db, array $actor, string $operation, array $receiptArtifact, array $receiptPayload): array
{
    if (!function_exists('sop_ledger_append')) {
        throw new RuntimeException('finance_gateway_ledger_append_unavailable');
    }

    mh_finance_gateway_ensure_sop_schema($db);
    $eventType = substr('finance.gateway.' . $operation, 0, 64);
    $subjectId = 'fg:' . substr(hash('sha256', $operation . '|' . $receiptArtifact['sha256']), 0, 40);
    return sop_ledger_append(
        $db,
        [
            'tenant_id' => $actor['tenantId'],
            'persona_id' => $actor['personaId'] ?? '',
            'meta_human_id' => $actor['metaHumanId'] ?? '',
            'principal_id' => $actor['principalId'],
            'username' => $actor['username'],
        ],
        $eventType,
        'settlement_instruction',
        $subjectId,
        [
            'operation' => $operation,
            'receipt_path' => $receiptArtifact['path'],
            'receipt_sha256' => $receiptArtifact['sha256'],
            'actor' => $actor,
            'summary' => $receiptPayload['summary'] ?? [],
            'recordedAtUtc' => $receiptPayload['recordedAtUtc'] ?? gmdate('c'),
        ],
        $actor['role'] !== '' ? $actor['role'] : null
    );
}

function mh_finance_gateway_result_summary(mixed $result): array
{
    if (!is_array($result)) {
        return ['value' => $result];
    }

    $summary = [];
    $keys = [
        'tenantId',
        'eventKey',
        'entryKey',
        'runKey',
        'exportKey',
        'receiptPath',
        'receiptSha256',
        'artifactPath',
        'artifactSha256',
        'manifestPath',
        'manifestSha256',
        'status',
        'acceptanceStatus',
        'processed',
        'exceptions',
        'selected',
    ];
    foreach ($keys as $key) {
        if (array_key_exists($key, $result)) {
            $summary[$key] = $result[$key];
        }
    }
    if ($summary === []) {
        $summary = $result;
    }
    return $summary;
}

function mh_finance_gateway_execute_mutation(string $operation, array $args, callable $callback): array
{
    $actor = mh_finance_gateway_actor_context($args);
    if ($actor['tenantId'] === '') {
        throw new InvalidArgumentException('tenant_id_required');
    }

    $db = mh_finance_gateway_open_db($actor['tenantId']);
    $recordedAtUtc = gmdate('c');
    try {
        $result = $callback($db, $actor);
        $resultArray = is_array($result) ? $result : ['value' => $result];
        $receiptPayload = [
            'kind' => 'finance.gateway.receipt',
            'operation' => $operation,
            'recordedAtUtc' => $recordedAtUtc,
            'actor' => $actor,
            'request' => $args,
            'outcome' => 'succeeded',
            'summary' => mh_finance_gateway_result_summary($resultArray),
        ];
        $receiptArtifact = mh_finance_write_json_artifact(
            mh_finance_gateway_receipt_path($actor['tenantId'], $operation, $recordedAtUtc),
            $receiptPayload
        );
        $ledger = mh_finance_gateway_anchor_receipt($db, $actor, $operation, $receiptArtifact, $receiptPayload);

        mh_finance_append_audit_line($actor['tenantId'], [
            'kind' => 'finance.gateway.mutation',
            'operation' => $operation,
            'outcome' => 'succeeded',
            'receiptPath' => $receiptArtifact['path'],
            'receiptSha256' => $receiptArtifact['sha256'],
            'ledgerEventId' => (string)($ledger['ledger_event_id'] ?? ''),
            'ledgerEventHash' => (string)($ledger['event_hash'] ?? ''),
            'ledgerPrevHash' => (string)($ledger['prev_event_hash'] ?? ''),
            'recordedAtUtc' => $recordedAtUtc,
            'actor' => $actor,
        ]);

        $resultArray['gateway'] = [
            'operation' => $operation,
            'receiptPath' => $receiptArtifact['path'],
            'receiptSha256' => $receiptArtifact['sha256'],
            'ledgerEventId' => (string)($ledger['ledger_event_id'] ?? ''),
            'ledgerEventHash' => (string)($ledger['event_hash'] ?? ''),
            'ledgerPrevHash' => (string)($ledger['prev_event_hash'] ?? ''),
        ];
        return $resultArray;
    } catch (Throwable $e) {
        $receiptPayload = [
            'kind' => 'finance.gateway.receipt',
            'operation' => $operation,
            'recordedAtUtc' => $recordedAtUtc,
            'actor' => $actor,
            'request' => $args,
            'outcome' => 'failed',
            'error' => [
                'message' => $e->getMessage(),
                'type' => get_class($e),
            ],
        ];
        $receiptArtifact = mh_finance_write_json_artifact(
            mh_finance_gateway_receipt_path($actor['tenantId'], $operation . '-failed', $recordedAtUtc),
            $receiptPayload
        );
        $ledger = mh_finance_gateway_anchor_receipt($db, $actor, $operation . '.failed', $receiptArtifact, $receiptPayload);

        mh_finance_append_audit_line($actor['tenantId'], [
            'kind' => 'finance.gateway.mutation',
            'operation' => $operation,
            'outcome' => 'failed',
            'receiptPath' => $receiptArtifact['path'],
            'receiptSha256' => $receiptArtifact['sha256'],
            'ledgerEventId' => (string)($ledger['ledger_event_id'] ?? ''),
            'ledgerEventHash' => (string)($ledger['event_hash'] ?? ''),
            'ledgerPrevHash' => (string)($ledger['prev_event_hash'] ?? ''),
            'recordedAtUtc' => $recordedAtUtc,
            'actor' => $actor,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}

function mh_finance_gateway_process_pending_events(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('process_pending_events', $args, function (PDO $db, array $actor) use ($args): array {
        $limit = isset($args['limit']) ? (int)$args['limit'] : 100;
        $limit = max(1, min(500, $limit));
        return mh_finance_process_pending_events($db, $actor['tenantId'], $limit);
    });
}

function mh_finance_gateway_run_reconciliation(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('run_reconciliation', $args, function (PDO $db, array $actor): array {
        return mh_finance_reconciliation_snapshot($db, $actor['tenantId']);
    });
}

function mh_finance_gateway_generate_board_pack(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('generate_board_pack', $args, function (PDO $db, array $actor) use ($args): array {
        return mh_finance_generate_board_pack($db, $actor['tenantId'], isset($args['asOfUtc']) ? (string)$args['asOfUtc'] : null);
    });
}

function mh_finance_gateway_capture_grid_terminal_event(array $args = []): ?array
{
    return mh_finance_gateway_execute_mutation('capture_grid_terminal_event', $args, function (PDO $db, array $actor) use ($args): array {
        $result = mh_finance_post_grid_terminal_event($db, $actor['tenantId'], $args);
        return is_array($result) ? $result : ['noop' => true];
    });
}

function mh_finance_gateway_requeue_exception(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('requeue_exception', $args, function (PDO $db, array $actor) use ($args): array {
        $exceptionId = isset($args['exceptionId']) ? (int)$args['exceptionId'] : 0;
        if ($exceptionId <= 0) {
            throw new InvalidArgumentException('exception_id_required');
        }
        return mh_finance_requeue_exception($db, $actor['tenantId'], $exceptionId, $actor);
    });
}

function mh_finance_gateway_resolve_exception(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('resolve_exception', $args, function (PDO $db, array $actor) use ($args): array {
        $exceptionId = isset($args['exceptionId']) ? (int)$args['exceptionId'] : 0;
        if ($exceptionId <= 0) {
            throw new InvalidArgumentException('exception_id_required');
        }
        $status = trim((string)($args['status'] ?? 'resolved'));
        $note = isset($args['note']) ? trim((string)$args['note']) : null;
        return mh_finance_resolve_exception($db, $actor['tenantId'], $exceptionId, $status, $actor, $note);
    });
}

function mh_finance_gateway_dispute_exception(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('dispute_exception', $args, function (PDO $db, array $actor) use ($args): array {
        $exceptionId = isset($args['exceptionId']) ? (int)$args['exceptionId'] : 0;
        if ($exceptionId <= 0) {
            throw new InvalidArgumentException('exception_id_required');
        }
        $note = isset($args['note']) ? trim((string)$args['note']) : null;
        return mh_finance_dispute_exception($db, $actor['tenantId'], $exceptionId, $actor, $note);
    });
}

function mh_finance_gateway_accept_journal(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('accept_journal', $args, function (PDO $db, array $actor) use ($args): array {
        $entryKey = trim((string)($args['entryKey'] ?? ''));
        if ($entryKey === '') {
            throw new InvalidArgumentException('entry_key_required');
        }
        $note = isset($args['note']) ? trim((string)$args['note']) : null;
        return mh_finance_accept_journal_entry($db, $actor['tenantId'], $entryKey, $actor, $note);
    });
}

function mh_finance_gateway_dispute_journal(array $args = []): array
{
    return mh_finance_gateway_execute_mutation('dispute_journal', $args, function (PDO $db, array $actor) use ($args): array {
        $entryKey = trim((string)($args['entryKey'] ?? ''));
        if ($entryKey === '') {
            throw new InvalidArgumentException('entry_key_required');
        }
        $note = isset($args['note']) ? trim((string)$args['note']) : null;
        return mh_finance_dispute_journal_entry($db, $actor['tenantId'], $entryKey, $actor, $note);
    });
}
