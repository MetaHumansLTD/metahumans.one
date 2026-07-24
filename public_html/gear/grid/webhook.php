<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';
require_once dirname(__DIR__, 2) . '/.cue/sop.php';
require_once dirname(__DIR__, 2) . '/gear/accounting/finance_gateway.php';
require_once __DIR__ . '/sr_client.php';
require_once __DIR__ . '/grid_db.php';

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
ob_start();

function mh_grid_webhook_json_exit(int $code, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_grid_webhook_response(int $code, array $payload): array
{
    return ['status' => $code, 'payload' => $payload];
}

function mh_grid_webhook_tenants_root(array $options = []): string
{
    $root = isset($options['tenants_root']) && is_string($options['tenants_root'])
        ? trim((string)$options['tenants_root'])
        : '/data/tenants';
    $root = rtrim($root, '/');
    return $root !== '' ? $root : '/data/tenants';
}

function mh_grid_webhook_tenant_id_from_payload(array $payload): string
{
    $tenantId = '';
    $transaction = $payload['transaction'] ?? null;
    if (is_array($transaction)) {
        $tenantId = trim((string)($transaction['platformCustomerId'] ?? ''));
    }
    $data = $payload['data'] ?? null;
    if ($tenantId === '' && is_array($data) && isset($data['platformCustomerId'])) {
        $tenantId = trim((string)$data['platformCustomerId']);
    }
    return $tenantId !== '' ? $tenantId : 'platform:grid';
}

function mh_grid_webhook_payload_id(array $payload): string
{
    $webhookId = trim((string)($payload['id'] ?? ''));
    if ($webhookId === '') {
        $webhookId = trim((string)($payload['webhookId'] ?? ''));
    }
    return $webhookId;
}

function mh_grid_webhook_extract_transaction(array $payload): array
{
    $transaction = $payload['transaction'] ?? null;
    if (!is_array($transaction)) {
        return [];
    }

    $transactionId = trim((string)($transaction['id'] ?? ''));
    if ($transactionId === '') {
        $transactionId = trim((string)($transaction['transactionId'] ?? ''));
    }

    $quoteId = trim((string)($transaction['quoteId'] ?? ''));
    $status = strtoupper(trim((string)($transaction['status'] ?? '')));
    $source = isset($transaction['source']) && is_array($transaction['source']) ? $transaction['source'] : [];
    $sourceAccountId = trim((string)($source['accountId'] ?? ''));
    $settledAt = trim((string)($transaction['settledAt'] ?? ''));
    $timestamp = trim((string)($payload['timestamp'] ?? ''));

    return [
        'transactionId' => $transactionId,
        'quoteId' => $quoteId,
        'status' => $status,
        'sourceAccountId' => $sourceAccountId,
        'settledAt' => $settledAt,
        'timestamp' => $timestamp,
        'raw' => $transaction,
    ];
}

function mh_grid_webhook_terminal_transaction_status(string $status): bool
{
    return in_array(strtoupper(trim($status)), ['COMPLETED', 'REJECTED', 'FAILED', 'REFUNDED', 'EXPIRED'], true);
}

function mh_grid_webhook_reconcile_quote(PDO $db, string $tenantId, string $webhookType, array $payload): ?array
{
    if (!in_array($webhookType, ['OUTGOING_PAYMENT', 'INCOMING_PAYMENT'], true)) {
        return null;
    }

    $transaction = mh_grid_webhook_extract_transaction($payload);
    $quoteId = trim((string)($transaction['quoteId'] ?? ''));
    if ($quoteId === '') {
        return [
            'matched' => false,
            'reason' => 'missing_quote_id',
            'webhookType' => $webhookType,
        ];
    }

    $status = trim((string)($transaction['status'] ?? ''));
    $transactionId = trim((string)($transaction['transactionId'] ?? ''));
    $sourceAccountId = trim((string)($transaction['sourceAccountId'] ?? ''));
    $executedAt = null;
    $executedSource = trim((string)($transaction['settledAt'] ?? ''));
    if ($executedSource === '') {
        $executedSource = trim((string)($transaction['timestamp'] ?? ''));
    }
    if ($executedSource !== '') {
        $ts = strtotime($executedSource);
        if ($ts !== false) {
            $executedAt = gmdate('Y-m-d H:i:s', $ts);
        }
    }

    $stmt = $db->prepare("
        INSERT INTO mh_settlement_quotes
            (tenant_id, sr_internal_account_id, sr_quote_id, quote_status, transaction_id, created_at_utc, updated_at_utc, executed_at_utc)
        VALUES
            (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)
        ON DUPLICATE KEY UPDATE
            sr_internal_account_id = COALESCE(VALUES(sr_internal_account_id), sr_internal_account_id),
            quote_status = VALUES(quote_status),
            transaction_id = COALESCE(VALUES(transaction_id), transaction_id),
            updated_at_utc = UTC_TIMESTAMP(),
            executed_at_utc = CASE
                WHEN executed_at_utc IS NULL AND VALUES(executed_at_utc) IS NOT NULL THEN VALUES(executed_at_utc)
                ELSE executed_at_utc
            END
    ");
    $stmt->execute([
        $tenantId,
        ($sourceAccountId !== '' ? $sourceAccountId : null),
        $quoteId,
        ($status !== '' ? $status : 'unknown'),
        ($transactionId !== '' ? $transactionId : null),
        (mh_grid_webhook_terminal_transaction_status($status) ? $executedAt : null),
    ]);

    return [
        'matched' => true,
        'webhookType' => $webhookType,
        'quoteId' => $quoteId,
        'transactionId' => $transactionId,
        'status' => $status,
        'terminal' => mh_grid_webhook_terminal_transaction_status($status),
        'executedAtUtc' => $executedAt,
        'transaction' => $transaction,
    ];
}

function mh_grid_webhook_tenant_safe(string $tenantId): string
{
    $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : '';
    if ($tenantSafe === '') {
        $tenantSafe = substr(hash('sha256', $tenantId), 0, 16);
    }
    return $tenantSafe;
}

function mh_grid_webhook_date_utc(): string
{
    return gmdate('Y-m-d');
}

function mh_grid_webhook_name_safe(string $webhookId): string
{
    $nameSafe = preg_replace('/[^A-Za-z0-9:_-]/', '_', $webhookId);
    $nameSafe = preg_replace('/_+/', '_', (string)$nameSafe);
    $nameSafe = trim((string)$nameSafe, '_');
    if ($nameSafe === '') {
        $nameSafe = 'webhook_' . substr(hash('sha256', $webhookId), 0, 16);
    }
    return $nameSafe;
}

function mh_grid_webhook_write_rejected(string $tenantId, string $raw, string $sigB64, array $payloadCandidate = [], array $options = []): ?string
{
    $tenantSafe = mh_grid_webhook_tenant_safe($tenantId);
    $rejectRoot = mh_grid_webhook_tenants_root($options) . '/' . $tenantSafe . '/settlement/webhooks-rejected/' . mh_grid_webhook_date_utc();
    @mkdir($rejectRoot, 0770, true);

    $candidateId = isset($payloadCandidate['id']) ? trim((string)$payloadCandidate['id']) : '';
    $candidateType = isset($payloadCandidate['type']) ? trim((string)$payloadCandidate['type']) : '';

    $sigHeader = trim($sigB64);
    $sigNorm = strtr($sigHeader, '-_', '+/');
    $pad = strlen($sigNorm) % 4;
    if ($pad !== 0) {
        $sigNorm .= str_repeat('=', 4 - $pad);
    }
    $sigBytes = base64_decode($sigNorm, true);
    $sigLen = is_string($sigBytes) ? strlen($sigBytes) : 0;

    $rejectName = 'reject_' . substr(hash('sha256', $raw), 0, 16) . '.json';
    $rejectPath = $rejectRoot . '/' . $rejectName;
    $reject = [
        'received_at_utc' => gmdate('c'),
        'reason' => 'bad_signature',
        'tenant_id' => $tenantId,
        'webhook_id' => $candidateId,
        'webhook_type' => $candidateType,
        'signature_header' => $sigB64,
        'signature_len' => $sigLen,
        'raw_sha256' => hash('sha256', $raw),
        'raw_body' => $raw,
    ];
    $rejectJson = json_encode($reject, JSON_UNESCAPED_SLASHES);
    if (!is_string($rejectJson) || $rejectJson === '') {
        return null;
    }
    return @file_put_contents($rejectPath, $rejectJson, LOCK_EX) !== false ? $rejectPath : null;
}

function mh_grid_webhook_record_db(array $context): array
{
    $tenantId = trim((string)($context['tenant_id'] ?? ''));
    $webhookId = trim((string)($context['webhook_id'] ?? ''));
    $webhookType = trim((string)($context['webhook_type'] ?? ''));
    $rawPath = trim((string)($context['raw_path'] ?? ''));
    $rawSha = trim((string)($context['raw_sha256'] ?? ''));
    $duplicate = (bool)($context['duplicate'] ?? false);
    $payload = isset($context['payload']) && is_array($context['payload']) ? $context['payload'] : [];

    if ($tenantId === '' || $webhookId === '' || $webhookType === '' || $rawPath === '' || $rawSha === '') {
        return ['db_recorded' => false, 'ledger' => null];
    }

    $dbOk = false;
    $ledger = null;
    $quoteReconciliation = null;
    $financeEvent = null;

    $canTenantDb = str_starts_with($tenantId, 'user:')
        || str_starts_with($tenantId, 'persona:')
        || str_starts_with($tenantId, 'company:');

    if ($canTenantDb && function_exists('mh_apply_tenant_context')) {
        try {
            mh_apply_tenant_context($tenantId);
            $db = mh_grid_get_db();
            if ($db instanceof PDO) {
                mh_grid_ensure_tables($db);
                if (function_exists('sop_ensure_schema')) {
                    sop_ensure_schema($db);
                } elseif (function_exists('sop_ensure_tables')) {
                    sop_ensure_tables($db);
                }
                $stmt = $db->prepare("
                    INSERT INTO mh_settlement_webhooks
                        (tenant_id, webhook_id, webhook_type, signature_valid, raw_path, raw_sha256, received_at_utc)
                    VALUES
                        (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE
                        webhook_type = VALUES(webhook_type),
                        signature_valid = 1,
                        raw_path = VALUES(raw_path),
                        raw_sha256 = VALUES(raw_sha256)
                ");
                $stmt->execute([$tenantId, $webhookId, $webhookType, $rawPath, $rawSha]);
                $dbOk = true;
                $quoteReconciliation = mh_grid_webhook_reconcile_quote($db, $tenantId, $webhookType, $payload);
                if (($quoteReconciliation['terminal'] ?? false) === true && function_exists('mh_finance_gateway_capture_grid_terminal_event')) {
                    try {
                        $financeEvent = mh_finance_gateway_capture_grid_terminal_event([
                            'tenantId' => $tenantId,
                            'quoteId' => (string)($quoteReconciliation['quoteId'] ?? ''),
                            'transactionId' => (string)($quoteReconciliation['transactionId'] ?? ''),
                            'status' => (string)($quoteReconciliation['status'] ?? ''),
                            'occurredAtUtc' => (string)($quoteReconciliation['executedAtUtc'] ?? ''),
                            'transaction' => is_array($quoteReconciliation['transaction'] ?? null) ? $quoteReconciliation['transaction'] : [],
                            'sourceChannel' => 'grid_webhook',
                            'webhookId' => $webhookId,
                            'webhookType' => $webhookType,
                            'principalId' => 'system:grid_webhook',
                            'username' => 'grid-webhook',
                            'role' => 'system',
                            'source' => 'grid_webhook',
                        ]);
                    } catch (Throwable $e) {
                        if (function_exists('mh_finance_record_exception')) {
                            mh_finance_record_exception(
                                $db,
                                $tenantId,
                                'finance:grid:' . hash('sha256', implode('|', [
                                    $tenantId,
                                    (string)($quoteReconciliation['quoteId'] ?? ''),
                                    (string)($quoteReconciliation['transactionId'] ?? ''),
                                    strtoupper((string)($quoteReconciliation['status'] ?? '')),
                                ])),
                                'finance_event_post_failed',
                                $e->getMessage(),
                                [
                                    'source' => 'grid_webhook',
                                    'webhookId' => $webhookId,
                                    'webhookType' => $webhookType,
                                ]
                            );
                        }
                    }
                }

                if (!$duplicate && function_exists('sop_ledger_append')) {
                    $ctx = [
                        'tenant_id' => $tenantId,
                        'persona_id' => '',
                        'meta_human_id' => '',
                        'principal_id' => 'system:grid',
                        'username' => 'grid',
                    ];
                    $ledger = sop_ledger_append(
                        $db,
                        $ctx,
                        'grid.webhook.received',
                        'settlement_instruction',
                        $webhookId,
                        [
                            'webhook_id' => $webhookId,
                            'webhook_type' => $webhookType,
                            'tenant_id' => $tenantId,
                            'raw_path' => $rawPath,
                            'raw_sha256' => $rawSha,
                            'signature_valid' => true,
                        ]
                    );
                }
            }
        } catch (Throwable) {
            $dbOk = false;
        }
    }

    if (!$dbOk) {
        try {
            $db = mh_grid_get_db();
            if ($db instanceof PDO) {
                mh_grid_ensure_tables($db);
                $stmt = $db->prepare("
                    INSERT INTO mh_settlement_webhooks
                        (tenant_id, webhook_id, webhook_type, signature_valid, raw_path, raw_sha256, received_at_utc)
                    VALUES
                        (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE
                        webhook_type = VALUES(webhook_type),
                        signature_valid = 1,
                        raw_path = VALUES(raw_path),
                        raw_sha256 = VALUES(raw_sha256)
                ");
                $stmt->execute([$tenantId, $webhookId, $webhookType, $rawPath, $rawSha]);
                $dbOk = true;
                if ($quoteReconciliation === null) {
                    $quoteReconciliation = mh_grid_webhook_reconcile_quote($db, $tenantId, $webhookType, $payload);
                }
                if (($quoteReconciliation['terminal'] ?? false) === true && $financeEvent === null && function_exists('mh_finance_gateway_capture_grid_terminal_event')) {
                    try {
                        $financeEvent = mh_finance_gateway_capture_grid_terminal_event([
                            'tenantId' => $tenantId,
                            'quoteId' => (string)($quoteReconciliation['quoteId'] ?? ''),
                            'transactionId' => (string)($quoteReconciliation['transactionId'] ?? ''),
                            'status' => (string)($quoteReconciliation['status'] ?? ''),
                            'occurredAtUtc' => (string)($quoteReconciliation['executedAtUtc'] ?? ''),
                            'transaction' => is_array($quoteReconciliation['transaction'] ?? null) ? $quoteReconciliation['transaction'] : [],
                            'sourceChannel' => 'grid_webhook',
                            'webhookId' => $webhookId,
                            'webhookType' => $webhookType,
                            'principalId' => 'system:grid_webhook_fallback',
                            'username' => 'grid-webhook-fallback',
                            'role' => 'system',
                            'source' => 'grid_webhook_fallback',
                        ]);
                    } catch (Throwable $e) {
                        if (function_exists('mh_finance_record_exception')) {
                            mh_finance_record_exception(
                                $db,
                                $tenantId,
                                'finance:grid:' . hash('sha256', implode('|', [
                                    $tenantId,
                                    (string)($quoteReconciliation['quoteId'] ?? ''),
                                    (string)($quoteReconciliation['transactionId'] ?? ''),
                                    strtoupper((string)($quoteReconciliation['status'] ?? '')),
                                ])),
                                'finance_event_post_failed',
                                $e->getMessage(),
                                [
                                    'source' => 'grid_webhook_fallback',
                                    'webhookId' => $webhookId,
                                    'webhookType' => $webhookType,
                                ]
                            );
                        }
                    }
                }
            }
        } catch (Throwable) {
            $dbOk = false;
        }
    }

    return ['db_recorded' => $dbOk, 'ledger' => $ledger, 'quote_reconciliation' => $quoteReconciliation, 'finance_event' => $financeEvent];
}

function mh_grid_webhook_persist_payload(array $payload, string $raw, string $sigB64, array $options = []): array
{
    $webhookId = mh_grid_webhook_payload_id($payload);
    $webhookType = isset($payload['type']) ? trim((string)$payload['type']) : '';
    if ($webhookId === '' || $webhookType === '') {
        return ['ok' => false, 'error' => 'missing_fields'];
    }

    $tenantId = mh_grid_webhook_tenant_id_from_payload($payload);
    $tenantSafe = mh_grid_webhook_tenant_safe($tenantId);
    $date = mh_grid_webhook_date_utc();
    $tenantsRoot = mh_grid_webhook_tenants_root($options);

    $nameSafe = mh_grid_webhook_name_safe($webhookId);
    $root = $tenantsRoot . '/' . $tenantSafe . '/settlement/webhooks/' . $date;
    @mkdir($root, 0770, true);

    $path = $root . '/' . $nameSafe . '.json';
    $duplicate = false;
    $rawWritten = false;
    $rawToUse = $raw;
    $payloadToUse = $payload;

    if (is_file($path)) {
        $duplicate = true;
        $existingRaw = @file_get_contents($path);
        if (is_string($existingRaw) && trim($existingRaw) !== '') {
            $rawToUse = $existingRaw;
            $existingPayload = json_decode($existingRaw, true);
            if (is_array($existingPayload)) {
                $payloadToUse = $existingPayload;
            }
        }
    } else {
        if (@file_put_contents($path, $raw, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'persist_failed'];
        }
        $rawWritten = true;
    }

    $sha = hash('sha256', $rawToUse);
    $receiptRoot = $tenantsRoot . '/' . $tenantSafe . '/settlement/receipts/' . $date;
    $eventRoot = $tenantsRoot . '/' . $tenantSafe . '/settlement/events/' . $date;
    @mkdir($receiptRoot, 0770, true);
    @mkdir($eventRoot, 0770, true);

    $receiptPath = $receiptRoot . '/' . $nameSafe . '.json';
    $eventPath = $eventRoot . '/' . $nameSafe . '.json';
    $receivedAt = gmdate('c');

    $receipt = [
        'receipt_kind' => 'grid_webhook',
        'tenant_id' => $tenantId,
        'webhook_id' => $webhookId,
        'webhook_type' => $webhookType,
        'received_at_utc' => $receivedAt,
        'raw_path' => $path,
        'raw_sha256' => $sha,
        'signature_header_b64' => $sigB64,
    ];
    $event = [
        'event_type' => 'grid.webhook',
        'tenant_id' => $tenantId,
        'webhook_id' => $webhookId,
        'webhook_type' => $webhookType,
        'received_at_utc' => $receivedAt,
        'raw_path' => $path,
        'raw_sha256' => $sha,
        'payload' => $payloadToUse,
    ];

    $receiptJson = json_encode($receipt, JSON_UNESCAPED_SLASHES);
    $eventJson = json_encode($event, JSON_UNESCAPED_SLASHES);
    $receiptWritten = is_string($receiptJson) && $receiptJson !== ''
        ? @file_put_contents($receiptPath, $receiptJson, LOCK_EX) !== false
        : false;
    $eventWritten = is_string($eventJson) && $eventJson !== ''
        ? @file_put_contents($eventPath, $eventJson, LOCK_EX) !== false
        : false;

    $recordCallback = $options['record_callback'] ?? 'mh_grid_webhook_record_db';
    $recordResult = ['db_recorded' => false, 'ledger' => null, 'finance_event' => null];
    if (is_callable($recordCallback)) {
        $candidate = $recordCallback([
            'tenant_id' => $tenantId,
            'webhook_id' => $webhookId,
            'webhook_type' => $webhookType,
            'raw_path' => $path,
            'raw_sha256' => $sha,
            'duplicate' => $duplicate,
            'payload' => $payloadToUse,
        ]);
        if (is_array($candidate)) {
            $recordResult = array_merge($recordResult, $candidate);
        }
    }

    return [
        'ok' => true,
        'duplicate' => $duplicate,
        'raw_written' => $rawWritten,
        'tenant_id' => $tenantId,
        'webhook_id' => $webhookId,
        'webhook_type' => $webhookType,
        'raw_path' => $path,
        'raw_sha256' => $sha,
        'receipt_path' => $receiptPath,
        'receipt_written' => $receiptWritten,
        'event_path' => $eventPath,
        'event_written' => $eventWritten,
        'db_recorded' => (bool)($recordResult['db_recorded'] ?? false),
        'ledger' => $recordResult['ledger'] ?? null,
        'quote_reconciliation' => $recordResult['quote_reconciliation'] ?? null,
        'finance_event' => $recordResult['finance_event'] ?? null,
    ];
}

function mh_grid_webhook_handle_request(array $server, string $raw, array $options = []): array
{
    $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
        ? strtoupper(trim((string)$server['REQUEST_METHOD']))
        : 'GET';

    if ($method === 'GET' || $method === 'HEAD') {
        return mh_grid_webhook_response(200, ['ok' => true]);
    }
    if ($method !== 'POST') {
        return mh_grid_webhook_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    $pubKey = isset($options['public_key_pem']) && is_string($options['public_key_pem'])
        ? trim((string)$options['public_key_pem'])
        : '';
    if ($pubKey === '') {
        $cfg = mh_grid_read_cfg();
        $pubKey = isset($cfg['webhook_public_key_pem']) && is_string($cfg['webhook_public_key_pem'])
            ? trim((string)$cfg['webhook_public_key_pem'])
            : '';
    }
    if ($pubKey === '') {
        return mh_grid_webhook_response(503, ['ok' => false, 'error' => 'webhook_verification_not_configured']);
    }

    $sigB64 = isset($server['HTTP_X_GRID_SIGNATURE']) && is_string($server['HTTP_X_GRID_SIGNATURE'])
        ? trim((string)$server['HTTP_X_GRID_SIGNATURE'])
        : '';
    if ($sigB64 === '') {
        return mh_grid_webhook_response(401, ['ok' => false, 'error' => 'missing_signature']);
    }

    if (trim($raw) === '') {
        return mh_grid_webhook_response(400, ['ok' => false, 'error' => 'empty_body']);
    }

    if (!mh_grid_verify_webhook_signature($raw, $sigB64, $pubKey)) {
        $payloadCandidate = json_decode($raw, true);
        if (!is_array($payloadCandidate)) {
            $payloadCandidate = [];
        }
        $candidateTenant = mh_grid_webhook_tenant_id_from_payload($payloadCandidate);
        mh_grid_webhook_write_rejected($candidateTenant, $raw, $sigB64, $payloadCandidate, $options);
        return mh_grid_webhook_response(401, ['ok' => false, 'error' => 'bad_signature']);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return mh_grid_webhook_response(400, ['ok' => false, 'error' => 'invalid_json']);
    }

    $persisted = mh_grid_webhook_persist_payload($payload, $raw, $sigB64, $options);
    if (($persisted['ok'] ?? false) !== true) {
        $error = isset($persisted['error']) && is_string($persisted['error']) ? $persisted['error'] : 'persist_failed';
        $status = $error === 'missing_fields' ? 400 : 500;
        return mh_grid_webhook_response($status, ['ok' => false, 'error' => $error]);
    }

    return mh_grid_webhook_response(200, $persisted);
}

function mh_grid_webhook_recursive_delete(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $target = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($target);
        } else {
            @unlink($target);
        }
    }
    @rmdir($path);
}

function mh_grid_webhook_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mh_grid_webhook_test_sign(string $rawBody, mixed $privateKey): string
{
    $signature = '';
    $ok = openssl_sign($rawBody, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok || $signature === '') {
        throw new RuntimeException('sign_failed');
    }
    return base64_encode($signature);
}

function mh_grid_webhook_run_self_tests(): array
{
    $tempRoot = '/tmp/grid-webhook-self-test-' . bin2hex(random_bytes(6));
    $tenantsRoot = $tempRoot . '/tenants';
    @mkdir($tenantsRoot, 0770, true);

    try {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($privateKey === false) {
            throw new RuntimeException('private_key_create_failed');
        }
        $details = openssl_pkey_get_details($privateKey);
        if (!is_array($details) || !isset($details['key']) || !is_string($details['key']) || trim($details['key']) === '') {
            throw new RuntimeException('public_key_extract_failed');
        }
        $publicKeyPem = trim($details['key']);

        $payload = [
            'id' => 'Webhook:test-' . bin2hex(random_bytes(4)),
            'type' => 'TEST',
            'timestamp' => gmdate('c'),
            'data' => [
                'platformCustomerId' => 'platform:grid',
            ],
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('payload_encode_failed');
        }

        $sig = mh_grid_webhook_test_sign($raw, $privateKey);
        $header = json_encode(['v' => 1, 's' => $sig], JSON_UNESCAPED_SLASHES);
        if (!is_string($header) || $header === '') {
            throw new RuntimeException('header_encode_failed');
        }

        $recordCalls = [];
        $recordCallback = function (array $context) use (&$recordCalls): array {
            $recordCalls[] = $context;
            return ['db_recorded' => true, 'ledger' => ['mode' => 'self_test']];
        };

        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_GRID_SIGNATURE' => $header,
        ];
        $result = mh_grid_webhook_handle_request($server, $raw, [
            'public_key_pem' => $publicKeyPem,
            'tenants_root' => $tenantsRoot,
            'record_callback' => $recordCallback,
        ]);
        mh_grid_webhook_assert((int)($result['status'] ?? 0) === 200, 'expected_success_status');
        $successPayload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
        mh_grid_webhook_assert(($successPayload['ok'] ?? false) === true, 'expected_success_payload');
        mh_grid_webhook_assert(($successPayload['db_recorded'] ?? false) === true, 'expected_db_recorded');
        mh_grid_webhook_assert(($successPayload['duplicate'] ?? true) === false, 'expected_first_write');

        $rawPath = (string)($successPayload['raw_path'] ?? '');
        $receiptPath = (string)($successPayload['receipt_path'] ?? '');
        $eventPath = (string)($successPayload['event_path'] ?? '');
        mh_grid_webhook_assert($rawPath !== '' && is_file($rawPath), 'raw_artifact_missing');
        mh_grid_webhook_assert($receiptPath !== '' && is_file($receiptPath), 'receipt_artifact_missing');
        mh_grid_webhook_assert($eventPath !== '' && is_file($eventPath), 'event_artifact_missing');

        $receipt = json_decode((string)file_get_contents($receiptPath), true);
        $event = json_decode((string)file_get_contents($eventPath), true);
        mh_grid_webhook_assert(is_array($receipt) && ($receipt['webhook_id'] ?? '') === $payload['id'], 'receipt_content_invalid');
        mh_grid_webhook_assert(is_array($event) && ($event['payload']['id'] ?? '') === $payload['id'], 'event_content_invalid');

        $duplicate = mh_grid_webhook_handle_request($server, $raw, [
            'public_key_pem' => $publicKeyPem,
            'tenants_root' => $tenantsRoot,
            'record_callback' => $recordCallback,
        ]);
        $duplicatePayload = is_array($duplicate['payload'] ?? null) ? $duplicate['payload'] : [];
        mh_grid_webhook_assert((int)($duplicate['status'] ?? 0) === 200, 'expected_duplicate_status');
        mh_grid_webhook_assert(($duplicatePayload['duplicate'] ?? false) === true, 'expected_duplicate_flag');

        $badHeader = json_encode(['v' => 1, 's' => substr($sig, 0, -2) . 'xx'], JSON_UNESCAPED_SLASHES);
        if (!is_string($badHeader) || $badHeader === '') {
            throw new RuntimeException('bad_header_encode_failed');
        }
        $rejected = mh_grid_webhook_handle_request([
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_GRID_SIGNATURE' => $badHeader,
        ], $raw, [
            'public_key_pem' => $publicKeyPem,
            'tenants_root' => $tenantsRoot,
            'record_callback' => $recordCallback,
        ]);
        mh_grid_webhook_assert((int)($rejected['status'] ?? 0) === 401, 'expected_rejected_status');

        $rejectDir = $tenantsRoot . '/' . mh_grid_webhook_tenant_safe('platform:grid') . '/settlement/webhooks-rejected/' . mh_grid_webhook_date_utc();
        $rejectFiles = is_dir($rejectDir) ? glob($rejectDir . '/*.json') : [];
        mh_grid_webhook_assert(is_array($rejectFiles) && $rejectFiles !== [], 'rejected_artifact_missing');

        return [
            'ok' => true,
            'tests' => 3,
            'record_calls' => count($recordCalls),
            'artifacts' => [
                'raw_path' => $rawPath,
                'receipt_path' => $receiptPath,
                'event_path' => $eventPath,
                'reject_dir' => $rejectDir,
            ],
        ];
    } finally {
        mh_grid_webhook_recursive_delete($tempRoot);
    }
}

function mh_grid_webhook_cli_main(array $argv): int
{
    $args = $argv;
    array_shift($args);
    if (in_array('--self-test', $args, true)) {
        try {
            $result = mh_grid_webhook_run_self_tests();
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
            return 0;
        } catch (Throwable $e) {
            fwrite(STDERR, json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
            return 1;
        }
    }

    fwrite(STDERR, "usage: php webhook.php --self-test\n");
    return 2;
}

function mh_grid_webhook_dispatch(): void
{
    $raw = file_get_contents('php://input');
    $result = mh_grid_webhook_handle_request($_SERVER, is_string($raw) ? $raw : '');
    $status = isset($result['status']) ? (int)$result['status'] : 500;
    $payload = isset($result['payload']) && is_array($result['payload']) ? $result['payload'] : ['ok' => false, 'error' => 'invalid_handler_response'];
    mh_grid_webhook_json_exit($status, $payload);
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(mh_grid_webhook_cli_main($argv ?? []));
}

mh_grid_webhook_dispatch();
