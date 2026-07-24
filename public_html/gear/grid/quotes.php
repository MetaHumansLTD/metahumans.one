<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) {
    define('CUE_DISABLE_AUTO_UI', true);
}
if (!defined('CUE_LAYOUT_MANUAL')) {
    define('CUE_LAYOUT_MANUAL', true);
}

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';
require_once __DIR__ . '/sr_client.php';
require_once __DIR__ . '/grid_db.php';
require_once __DIR__ . '/internal_accounts.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function mh_grid_quotes_json(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_grid_quotes_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_grid_quotes_current_user(): string
{
    $user = $_SESSION['mh_auth_user'] ?? '';
    return is_string($user) ? trim($user) : '';
}

function mh_grid_quotes_current_tenant_id(): string
{
    if (function_exists('mh_grid_current_tenant_id')) {
        return mh_grid_current_tenant_id();
    }

    $user = mh_grid_quotes_current_user();
    if ($user === '') {
        return '';
    }

    return 'user:' . strtolower($user);
}

function mh_grid_quotes_error_context(): array
{
    return [
        'currentUser' => mh_grid_quotes_current_user(),
        'tenantId' => mh_grid_quotes_current_tenant_id(),
        'loginRedirect' => '/auth/login.php?redirect=' . rawurlencode('/hub/grid/passkey.php'),
    ];
}

function mh_grid_quotes_db(): PDO
{
    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('db_unavailable');
    }
    mh_grid_ensure_tables($db);
    return $db;
}

function mh_grid_quotes_account_id_for_tenant(PDO $db, string $tenantId): string
{
    $stmt = $db->prepare("
        SELECT sr_internal_account_id
        FROM mh_settlement_accounts
        WHERE tenant_id = ? AND account_type = 'EMBEDDED_WALLET'
        ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $accountId = trim((string)($row['sr_internal_account_id'] ?? ''));
        if ($accountId !== '') {
            return $accountId;
        }
    }

    $discover = mh_grid_discover_embedded_wallet_accounts_for_tenant($tenantId);
    if (($discover['ok'] ?? false) !== true) {
        return '';
    }

    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return '';
    }

    return trim((string)($row['sr_internal_account_id'] ?? ''));
}

function mh_grid_quotes_active_session(PDO $db, string $tenantId): ?array
{
    $stmt = $db->prepare("
        SELECT sr_auth_credential_id, sr_auth_session_id, session_status, expires_at_utc, raw_snapshot_json
        FROM mh_settlement_auth_sessions
        WHERE tenant_id = ?
          AND (expires_at_utc IS NULL OR expires_at_utc > UTC_TIMESTAMP())
        ORDER BY expires_at_utc DESC, updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $raw = [];
    $snapshot = trim((string)($row['raw_snapshot_json'] ?? ''));
    if ($snapshot !== '') {
        $decoded = json_decode($snapshot, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    }

    return [
        'credentialId' => trim((string)($row['sr_auth_credential_id'] ?? '')),
        'sessionId' => trim((string)($row['sr_auth_session_id'] ?? '')),
        'status' => trim((string)($row['session_status'] ?? 'active')),
        'expiresAt' => isset($raw['expiresAt']) ? trim((string)$raw['expiresAt']) : trim((string)($row['expires_at_utc'] ?? '')),
    ];
}

function mh_grid_quotes_extract_signature_requirement(array $quote): array
{
    $instructionSets = [];
    foreach (['paymentInstructions', 'fundingPaymentInstructions'] as $field) {
        if (isset($quote[$field]) && is_array($quote[$field])) {
            $instructionSets[$field] = $quote[$field];
        }
    }

    foreach ($instructionSets as $field => $instructions) {
        foreach ($instructions as $index => $instruction) {
            if (!is_array($instruction)) {
                continue;
            }
            $accountInfo = $instruction['accountOrWalletInfo'] ?? null;
            if (!is_array($accountInfo)) {
                continue;
            }

            $accountType = strtoupper(trim((string)($accountInfo['accountType'] ?? '')));
            $payloadToSign = trim((string)($accountInfo['payloadToSign'] ?? ''));
            if ($accountType === 'EMBEDDED_WALLET' && $payloadToSign !== '') {
                return [
                    'requiresGridWalletSignature' => true,
                    'payloadToSign' => $payloadToSign,
                    'instructionField' => $field,
                    'instructionIndex' => (int)$index,
                ];
            }
        }
    }

    return [
        'requiresGridWalletSignature' => false,
        'payloadToSign' => '',
        'instructionField' => null,
        'instructionIndex' => null,
    ];
}

function mh_grid_quotes_to_sql_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return gmdate('Y-m-d H:i:s', $ts);
}

function mh_grid_quotes_quote_id(array $quote): string
{
    $quoteId = trim((string)($quote['id'] ?? ''));
    if ($quoteId === '') {
        $quoteId = trim((string)($quote['quoteId'] ?? ''));
    }
    return $quoteId;
}

function mh_grid_quotes_transaction_id(array $payload): string
{
    $transactionId = trim((string)($payload['transactionId'] ?? ''));
    if ($transactionId === '') {
        $transactionId = trim((string)($payload['id'] ?? ''));
        if ($transactionId !== '' && stripos($transactionId, 'Transaction:') !== 0) {
            $transactionId = '';
        }
    }
    return $transactionId;
}

function mh_grid_quotes_store_created(PDO $db, string $tenantId, string $fallbackAccountId, array $quoteRequest, array $quote): void
{
    $quoteId = mh_grid_quotes_quote_id($quote);
    if ($quoteId === '') {
        return;
    }

    $signatureRequirement = mh_grid_quotes_extract_signature_requirement($quote);
    $source = isset($quote['source']) && is_array($quote['source']) ? $quote['source'] : [];
    $destination = isset($quote['destination']) && is_array($quote['destination']) ? $quote['destination'] : [];
    $sourceAccountId = trim((string)($source['accountId'] ?? ''));
    if ($sourceAccountId === '') {
        $sourceAccountId = $fallbackAccountId;
    }

    $stmt = $db->prepare("
        INSERT INTO mh_settlement_quotes
            (tenant_id, sr_internal_account_id, sr_quote_id, quote_status, source_type, destination_type, requires_grid_wallet_signature, payload_to_sign, transaction_id, expires_at_utc, raw_request_json, raw_snapshot_json, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            sr_internal_account_id = VALUES(sr_internal_account_id),
            quote_status = VALUES(quote_status),
            source_type = VALUES(source_type),
            destination_type = VALUES(destination_type),
            requires_grid_wallet_signature = VALUES(requires_grid_wallet_signature),
            payload_to_sign = VALUES(payload_to_sign),
            transaction_id = COALESCE(VALUES(transaction_id), transaction_id),
            expires_at_utc = VALUES(expires_at_utc),
            raw_request_json = VALUES(raw_request_json),
            raw_snapshot_json = VALUES(raw_snapshot_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        $tenantId,
        ($sourceAccountId !== '' ? $sourceAccountId : null),
        $quoteId,
        trim((string)($quote['status'] ?? 'unknown')),
        trim((string)($source['sourceType'] ?? '')),
        trim((string)($destination['destinationType'] ?? '')),
        !empty($signatureRequirement['requiresGridWalletSignature']) ? 1 : 0,
        trim((string)($signatureRequirement['payloadToSign'] ?? '')) ?: null,
        mh_grid_quotes_transaction_id($quote) ?: null,
        mh_grid_quotes_to_sql_datetime((string)($quote['expiresAt'] ?? '')),
        json_encode($quoteRequest, JSON_UNESCAPED_SLASHES),
        json_encode($quote, JSON_UNESCAPED_SLASHES),
    ]);
}

function mh_grid_quotes_store_executed(PDO $db, string $tenantId, string $quoteId, array $executeResponse): void
{
    $quoteId = trim($quoteId);
    if ($quoteId === '') {
        return;
    }

    $transactionId = mh_grid_quotes_transaction_id($executeResponse);

    $stmt = $db->prepare("
        UPDATE mh_settlement_quotes
        SET
            transaction_id = COALESCE(?, transaction_id),
            raw_execute_response_json = ?,
            executed_at_utc = UTC_TIMESTAMP(),
            updated_at_utc = UTC_TIMESTAMP()
        WHERE tenant_id = ? AND sr_quote_id = ?
    ");
    $stmt->execute([
        ($transactionId !== '' ? $transactionId : null),
        json_encode($executeResponse, JSON_UNESCAPED_SLASHES),
        $tenantId,
        $quoteId,
    ]);
}

function mh_grid_quotes_get_local(PDO $db, string $tenantId, string $quoteId): ?array
{
    $stmt = $db->prepare("
        SELECT sr_quote_id, quote_status, requires_grid_wallet_signature, payload_to_sign, transaction_id, raw_snapshot_json, raw_execute_response_json
        FROM mh_settlement_quotes
        WHERE tenant_id = ? AND sr_quote_id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $quoteId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_grid_quotes_recent(PDO $db, string $tenantId, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $db->prepare("
        SELECT sr_quote_id, sr_internal_account_id, quote_status, source_type, destination_type, requires_grid_wallet_signature, payload_to_sign, transaction_id, expires_at_utc, created_at_utc, updated_at_utc, executed_at_utc
        FROM mh_settlement_quotes
        WHERE tenant_id = ?
        ORDER BY COALESCE(updated_at_utc, created_at_utc) DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$tenantId]);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'quoteId' => trim((string)($row['sr_quote_id'] ?? '')),
            'accountId' => trim((string)($row['sr_internal_account_id'] ?? '')),
            'status' => trim((string)($row['quote_status'] ?? 'unknown')),
            'sourceType' => trim((string)($row['source_type'] ?? '')),
            'destinationType' => trim((string)($row['destination_type'] ?? '')),
            'requiresGridWalletSignature' => !empty($row['requires_grid_wallet_signature']),
            'payloadToSign' => trim((string)($row['payload_to_sign'] ?? '')),
            'transactionId' => trim((string)($row['transaction_id'] ?? '')),
            'expiresAt' => trim((string)($row['expires_at_utc'] ?? '')),
            'createdAt' => trim((string)($row['created_at_utc'] ?? '')),
            'updatedAt' => trim((string)($row['updated_at_utc'] ?? '')),
            'executedAt' => trim((string)($row['executed_at_utc'] ?? '')),
        ];
    }

    return $rows;
}

function mh_grid_quotes_status_payload(): array
{
    $tenantId = mh_grid_quotes_current_tenant_id();
    if ($tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $db = mh_grid_quotes_db();
    $accountId = mh_grid_quotes_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        throw new RuntimeException('embedded_wallet_account_missing');
    }

    return [
        'ok' => true,
        'tenantId' => $tenantId,
        'accountId' => $accountId,
        'activeSession' => mh_grid_quotes_active_session($db, $tenantId),
        'recentQuotes' => mh_grid_quotes_recent($db, $tenantId),
    ];
}

function mh_grid_quotes_normalize_request(array $input, string $tenantAccountId): array
{
    $quoteRequest = $input['quoteRequest'] ?? ($input['request'] ?? null);
    if (!is_array($quoteRequest)) {
        throw new RuntimeException('invalid_quote_request');
    }

    $useTenantEmbeddedWalletSource = !empty($input['useTenantEmbeddedWalletSource']);
    if ($useTenantEmbeddedWalletSource) {
        if (!isset($quoteRequest['source']) || !is_array($quoteRequest['source'])) {
            $quoteRequest['source'] = [];
        }
        if (!isset($quoteRequest['source']['sourceType']) || trim((string)$quoteRequest['source']['sourceType']) === '') {
            $quoteRequest['source']['sourceType'] = 'ACCOUNT';
        }
        if (!isset($quoteRequest['source']['accountId']) || trim((string)$quoteRequest['source']['accountId']) === '') {
            $quoteRequest['source']['accountId'] = $tenantAccountId;
        }
    }

    $forcedImmediatelyExecuteFalse = false;
    $source = isset($quoteRequest['source']) && is_array($quoteRequest['source']) ? $quoteRequest['source'] : [];
    $sourceType = strtoupper(trim((string)($source['sourceType'] ?? '')));
    $sourceAccountId = trim((string)($source['accountId'] ?? ''));
    if ($sourceType === 'ACCOUNT' && $sourceAccountId !== '' && hash_equals($tenantAccountId, $sourceAccountId) && !empty($quoteRequest['immediatelyExecute'])) {
        $quoteRequest['immediatelyExecute'] = false;
        $forcedImmediatelyExecuteFalse = true;
    }

    return [
        'quoteRequest' => $quoteRequest,
        'forcedImmediatelyExecuteFalse' => $forcedImmediatelyExecuteFalse,
    ];
}

function mh_grid_quotes_handle_status(): void
{
    mh_grid_quotes_json(200, mh_grid_quotes_status_payload());
}

function mh_grid_quotes_handle_create(array $input): void
{
    $tenantId = mh_grid_quotes_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_quotes_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $db = mh_grid_quotes_db();
    $accountId = mh_grid_quotes_account_id_for_tenant($db, $tenantId);
    if ($accountId === '') {
        mh_grid_quotes_json(409, ['ok' => false, 'error' => 'embedded_wallet_account_missing']);
    }

    $normalized = null;
    try {
        $normalized = mh_grid_quotes_normalize_request($input, $accountId);
    } catch (RuntimeException $e) {
        mh_grid_quotes_json(400, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $quoteRequest = is_array($normalized) ? ($normalized['quoteRequest'] ?? null) : null;
    if (!is_array($quoteRequest)) {
        mh_grid_quotes_json(400, ['ok' => false, 'error' => 'invalid_quote_request']);
    }
    $requestHash = hash('sha256', json_encode($quoteRequest, JSON_UNESCAPED_SLASHES));

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/quotes', [
        'json' => $quoteRequest,
        'idempotency_key' => mh_grid_idempotency_key($tenantId, 'create_quote', $requestHash),
    ]);
    if (($resp['ok'] ?? false) !== true) {
        mh_grid_quotes_json(400, [
            'ok' => false,
            'error' => 'grid_quote_create_failed',
            'detail' => $resp,
        ]);
    }

    $quote = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    $quoteId = mh_grid_quotes_quote_id($quote);
    if ($quoteId === '') {
        mh_grid_quotes_json(400, [
            'ok' => false,
            'error' => 'unexpected_grid_quote_response',
            'detail' => $resp,
        ]);
    }

    mh_grid_quotes_store_created($db, $tenantId, $accountId, $quoteRequest, $quote);
    $signatureRequirement = mh_grid_quotes_extract_signature_requirement($quote);

    mh_grid_quotes_json(201, [
        'ok' => true,
        'quote' => $quote,
        'quoteId' => $quoteId,
        'forcedImmediatelyExecuteFalse' => is_array($normalized) && !empty($normalized['forcedImmediatelyExecuteFalse']),
        'requiresGridWalletSignature' => !empty($signatureRequirement['requiresGridWalletSignature']),
        'payloadToSign' => trim((string)($signatureRequirement['payloadToSign'] ?? '')),
        'instructionField' => $signatureRequirement['instructionField'] ?? null,
        'instructionIndex' => $signatureRequirement['instructionIndex'] ?? null,
    ]);
}

function mh_grid_quotes_handle_execute(array $input): void
{
    $tenantId = mh_grid_quotes_current_tenant_id();
    if ($tenantId === '') {
        mh_grid_quotes_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    $quoteId = isset($input['quoteId']) ? trim((string)$input['quoteId']) : '';
    $gridWalletSignature = isset($input['gridWalletSignature']) ? trim((string)$input['gridWalletSignature']) : '';
    if ($quoteId === '') {
        mh_grid_quotes_json(400, ['ok' => false, 'error' => 'missing_quote_id']);
    }

    $db = mh_grid_quotes_db();
    $localQuote = mh_grid_quotes_get_local($db, $tenantId, $quoteId);
    $requiresSignature = is_array($localQuote) && !empty($localQuote['requires_grid_wallet_signature']);
    $payloadToSign = is_array($localQuote) ? trim((string)($localQuote['payload_to_sign'] ?? '')) : '';
    if ($requiresSignature && $gridWalletSignature === '') {
        mh_grid_quotes_json(400, [
            'ok' => false,
            'error' => 'missing_grid_wallet_signature',
            'requiresGridWalletSignature' => true,
            'payloadToSign' => $payloadToSign,
        ]);
    }

    $headers = [];
    if ($gridWalletSignature !== '') {
        $headers['Grid-Wallet-Signature'] = $gridWalletSignature;
    }

    $cfg = mh_grid_read_cfg();
    $resp = mh_grid_http_request($cfg, 'POST', '/quotes/' . rawurlencode($quoteId) . '/execute', [
        'headers' => $headers,
        'idempotency_key' => mh_grid_idempotency_key($tenantId, 'execute_quote', $quoteId),
    ]);
    if (($resp['ok'] ?? false) !== true) {
        mh_grid_quotes_json(400, [
            'ok' => false,
            'error' => 'grid_quote_execute_failed',
            'detail' => $resp,
            'requiresGridWalletSignature' => $requiresSignature,
            'payloadToSign' => $payloadToSign,
        ]);
    }

    $executeResponse = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    mh_grid_quotes_store_executed($db, $tenantId, $quoteId, $executeResponse);

    mh_grid_quotes_json(200, [
        'ok' => true,
        'quoteId' => $quoteId,
        'result' => $executeResponse,
        'recentQuotes' => mh_grid_quotes_recent($db, $tenantId),
    ]);
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : 'status';
$method = strtoupper(trim((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')));
$input = mh_grid_quotes_input();

try {
    if ($action === 'status' && $method === 'GET') {
        mh_grid_quotes_handle_status();
    }
    if ($action === 'create_quote' && $method === 'POST') {
        mh_grid_quotes_handle_create($input);
    }
    if ($action === 'execute_quote' && $method === 'POST') {
        mh_grid_quotes_handle_execute($input);
    }

    mh_grid_quotes_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
} catch (Throwable $e) {
    $status = 500;
    $error = 'internal_error';
    if ($e->getMessage() === 'auth_required') {
        $status = 401;
        $error = 'auth_required';
    } elseif ($e->getMessage() === 'embedded_wallet_account_missing') {
        $status = 409;
        $error = 'embedded_wallet_account_missing';
    }

    mh_grid_quotes_json($status, array_merge([
        'ok' => false,
        'error' => $error,
        'message' => $e->getMessage(),
    ], ($error === 'auth_required' || $error === 'embedded_wallet_account_missing') ? mh_grid_quotes_error_context() : []));
}
