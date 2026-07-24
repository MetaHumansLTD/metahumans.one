<?php
declare(strict_types=1);

define('MH_GRID_DASHBOARD_LIB_ONLY', true);
require_once __DIR__ . '/dashboard_data.php';

function mh_grid_transactions_string_param(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function mh_grid_transactions_int_param(string $key, int $default, int $min, int $max): int
{
    $value = $_GET[$key] ?? null;
    if (!is_scalar($value) || !is_numeric((string)$value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function mh_grid_transactions_parse_date(string $value): string
{
    if ($value === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTimeImmutable ? $dt->format('Y-m-d') : '';
}

function mh_grid_transactions_day_start(string $date): string
{
    return $date !== '' ? ($date . ' 00:00:00') : '';
}

function mh_grid_transactions_day_end(string $date): string
{
    return $date !== '' ? ($date . ' 23:59:59') : '';
}

function mh_grid_transactions_filters(): array
{
    $status = strtolower(mh_grid_transactions_string_param('status'));
    if (in_array($status, ['all', ''], true)) {
        $status = '';
    }

    $kind = strtolower(mh_grid_transactions_string_param('kind'));
    if (!in_array($kind, ['settlement', 'platform'], true)) {
        $kind = '';
    }

    $quotePageSize = mh_grid_transactions_int_param('quotePageSize', 10, 5, 100);
    $activityPageSize = mh_grid_transactions_int_param('activityPageSize', 12, 5, 100);

    return [
        'startDate' => mh_grid_transactions_parse_date(mh_grid_transactions_string_param('startDate')),
        'endDate' => mh_grid_transactions_parse_date(mh_grid_transactions_string_param('endDate')),
        'status' => $status,
        'kind' => $kind,
        'quotePage' => mh_grid_transactions_int_param('quotePage', 1, 1, 100000),
        'quotePageSize' => $quotePageSize,
        'activityPage' => mh_grid_transactions_int_param('activityPage', 1, 1, 100000),
        'activityPageSize' => $activityPageSize,
    ];
}

function mh_grid_transactions_quote_where(string $tenantId, array $filters, array &$params): string
{
    $clauses = ['tenant_id = ?'];
    $params[] = $tenantId;

    if (($filters['status'] ?? '') !== '') {
        $clauses[] = 'LOWER(quote_status) = ?';
        $params[] = strtolower((string)$filters['status']);
    }
    if (($filters['startDate'] ?? '') !== '') {
        $clauses[] = 'COALESCE(executed_at_utc, updated_at_utc, created_at_utc) >= ?';
        $params[] = mh_grid_transactions_day_start((string)$filters['startDate']);
    }
    if (($filters['endDate'] ?? '') !== '') {
        $clauses[] = 'COALESCE(executed_at_utc, updated_at_utc, created_at_utc) <= ?';
        $params[] = mh_grid_transactions_day_end((string)$filters['endDate']);
    }

    return implode(' AND ', $clauses);
}

function mh_grid_transactions_quote_rows(PDO $db, string $tenantId, array $filters, ?int $limit = null, int $offset = 0): array
{
    $params = [];
    $where = mh_grid_transactions_quote_where($tenantId, $filters, $params);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM mh_settlement_quotes WHERE {$where}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "
        SELECT sr_quote_id, sr_internal_account_id, quote_status, source_type, destination_type, transaction_id,
               raw_request_json, created_at_utc, updated_at_utc, executed_at_utc
        FROM mh_settlement_quotes
        WHERE {$where}
        ORDER BY COALESCE(executed_at_utc, updated_at_utc, created_at_utc) DESC, id DESC
    ";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $request = mh_grid_dashboard_json_decode($row['raw_request_json'] ?? '');
        $amount = isset($request['lockedCurrencyAmount']) && is_numeric($request['lockedCurrencyAmount'])
            ? (float)$request['lockedCurrencyAmount']
            : null;
        $destination = isset($request['destination']) && is_array($request['destination']) ? $request['destination'] : [];
        $currency = isset($destination['currency']) && is_string($destination['currency']) ? trim((string)$destination['currency']) : '';
        $rows[] = [
            'quoteId' => trim((string)($row['sr_quote_id'] ?? '')),
            'accountId' => trim((string)($row['sr_internal_account_id'] ?? '')),
            'status' => trim((string)($row['quote_status'] ?? 'unknown')),
            'sourceType' => trim((string)($row['source_type'] ?? '')),
            'destinationType' => trim((string)($row['destination_type'] ?? '')),
            'transactionId' => trim((string)($row['transaction_id'] ?? '')),
            'amountDisplay' => mh_grid_dashboard_format_money($amount, $currency),
            'createdAt' => trim((string)($row['created_at_utc'] ?? '')),
            'updatedAt' => trim((string)($row['updated_at_utc'] ?? '')),
            'executedAt' => trim((string)($row['executed_at_utc'] ?? '')),
            'happenedAt' => trim((string)($row['executed_at_utc'] ?? $row['updated_at_utc'] ?? $row['created_at_utc'] ?? '')),
        ];
    }

    return [
        'total' => $total,
        'rows' => $rows,
    ];
}

function mh_grid_transactions_paginate(array $rows, int $page, int $pageSize): array
{
    $total = count($rows);
    $pageSize = max(1, $pageSize);
    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min(max(1, $page), $totalPages);
    $offset = ($page - 1) * $pageSize;

    return [
        'rows' => array_slice($rows, $offset, $pageSize),
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrev' => $page > 1,
            'hasNext' => $page < $totalPages,
        ],
    ];
}

function mh_grid_transactions_activity_rows(array $quotes, array $ledger): array
{
    $rows = [];

    foreach ($quotes as $quote) {
        $rows[] = [
            'kind' => 'settlement',
            'title' => 'Grid quote ' . (trim((string)($quote['status'] ?? '')) !== '' ? strtolower((string)$quote['status']) : 'updated'),
            'category' => 'Grid settlement quote',
            'detail' => trim((string)($quote['sourceType'] ?? '')) . ' -> ' . trim((string)($quote['destinationType'] ?? '')),
            'amountDisplay' => trim((string)($quote['amountDisplay'] ?? 'Unavailable')),
            'status' => trim((string)($quote['status'] ?? 'unknown')),
            'happenedAt' => trim((string)($quote['happenedAt'] ?? '')),
            'reference' => trim((string)($quote['quoteId'] ?? '')),
            'accountId' => trim((string)($quote['accountId'] ?? '')),
            'source' => trim((string)($quote['sourceType'] ?? '')),
            'destination' => trim((string)($quote['destinationType'] ?? '')),
            'transactionId' => trim((string)($quote['transactionId'] ?? '')),
            'link' => '/hub/grid/payments.php',
        ];
    }

    foreach ($ledger as $entry) {
        $assetName = trim((string)($entry['assetName'] ?? 'Asset'));
        $rows[] = [
            'kind' => 'platform',
            'title' => $assetName !== '' ? $assetName : 'Platform asset activity',
            'category' => trim((string)($entry['serviceKey'] ?? '')) ?: 'Platform ledger',
            'detail' => trim((string)($entry['serviceKey'] ?? '')) ?: 'Ledger event',
            'amountDisplay' => (trim((string)($entry['direction'] ?? '')) !== '' ? strtoupper(trim((string)$entry['direction'])) . ' ' : '') . number_format((int)($entry['units'] ?? 0)),
            'status' => 'posted',
            'happenedAt' => trim((string)($entry['createdAt'] ?? '')),
            'reference' => trim((string)($entry['referenceId'] ?? '')),
            'accountId' => '',
            'source' => trim((string)($entry['assetKey'] ?? '')),
            'destination' => $assetName !== '' ? $assetName : 'Platform ledger',
            'transactionId' => '',
            'link' => '/hub/tokens/tokens.php',
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)($b['happenedAt'] ?? ''), (string)($a['happenedAt'] ?? ''));
    });

    return $rows;
}

function mh_grid_transactions_filter_activity(array $rows, array $filters): array
{
    $start = ($filters['startDate'] ?? '') !== '' ? mh_grid_transactions_day_start((string)$filters['startDate']) : '';
    $end = ($filters['endDate'] ?? '') !== '' ? mh_grid_transactions_day_end((string)$filters['endDate']) : '';
    $status = strtolower((string)($filters['status'] ?? ''));
    $kind = strtolower((string)($filters['kind'] ?? ''));

    return array_values(array_filter($rows, static function (array $row) use ($start, $end, $status, $kind): bool {
        $rowKind = strtolower(trim((string)($row['kind'] ?? '')));
        $rowStatus = strtolower(trim((string)($row['status'] ?? '')));
        $when = trim((string)($row['happenedAt'] ?? ''));

        if ($kind !== '' && $rowKind !== $kind) {
            return false;
        }
        if ($status !== '' && $rowStatus !== $status) {
            return false;
        }
        if ($start !== '' && ($when === '' || $when < $start)) {
            return false;
        }
        if ($end !== '' && ($when === '' || $when > $end)) {
            return false;
        }
        return true;
    }));
}

function mh_grid_transactions_export_url(array $filters, string $exportType): string
{
    $query = [
        'export' => $exportType,
    ];
    foreach (['startDate', 'endDate', 'status', 'kind'] as $key) {
        if (($filters[$key] ?? '') !== '') {
            $query[$key] = (string)$filters[$key];
        }
    }
    return '/gear/grid/transactions_data.php?' . http_build_query($query);
}

function mh_grid_transactions_output_csv(string $filename, array $header, array $rows): never
{
    if (!headers_sent()) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    $stream = fopen('php://output', 'wb');
    if ($stream === false) {
        throw new RuntimeException('csv_open_failed');
    }
    fputcsv($stream, $header);
    foreach ($rows as $row) {
        fputcsv($stream, $row);
    }
    fclose($stream);
    exit;
}

function mh_grid_transactions_export(array $filters): void
{
    $username = mh_grid_dashboard_current_user();
    $tenantId = mh_grid_dashboard_current_tenant_id();
    if ($username === '' || $tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $db = mh_grid_dashboard_db();
    $quotesData = mh_grid_transactions_quote_rows($db, $tenantId, $filters, 500, 0);
    $quotes = $quotesData['rows'];

    $ledger = [];
    try {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        if ($pdoTok instanceof PDO) {
            $tokenSummary = mh_grid_dashboard_token_summary($pdoTok, $username, 500);
            $ledger = is_array($tokenSummary['recentLedger'] ?? null) ? $tokenSummary['recentLedger'] : [];
        }
    } catch (Throwable) {
        $ledger = [];
    }

    $activity = mh_grid_transactions_filter_activity(mh_grid_transactions_activity_rows($quotes, $ledger), $filters);
    $export = strtolower(mh_grid_transactions_string_param('export'));

    if ($export === 'quotes_csv') {
        $rows = [];
        foreach ($quotes as $quote) {
            $rows[] = [
                $quote['happenedAt'] ?? '',
                $quote['quoteId'] ?? '',
                $quote['status'] ?? '',
                $quote['amountDisplay'] ?? '',
                $quote['sourceType'] ?? '',
                $quote['destinationType'] ?? '',
                $quote['transactionId'] ?? '',
                $quote['accountId'] ?? '',
            ];
        }
        mh_grid_transactions_output_csv(
            'grid-quotes-' . date('Ymd-His') . '.csv',
            ['happened_at', 'quote_id', 'status', 'amount', 'source_type', 'destination_type', 'transaction_id', 'account_id'],
            $rows
        );
    }

    if ($export === 'accounting_csv') {
        $rows = [];
        foreach ($activity as $row) {
            $rows[] = [
                $row['happenedAt'] ?? '',
                $row['kind'] ?? '',
                $row['category'] ?? '',
                $row['status'] ?? '',
                $row['amountDisplay'] ?? '',
                $row['source'] ?? '',
                $row['destination'] ?? '',
                $row['reference'] ?? '',
                $row['transactionId'] ?? '',
                $row['accountId'] ?? '',
                $row['link'] ?? '',
            ];
        }
        mh_grid_transactions_output_csv(
            'grid-accounting-handoff-' . date('Ymd-His') . '.csv',
            ['happened_at', 'kind', 'category', 'status', 'amount', 'source', 'destination', 'reference', 'transaction_id', 'account_id', 'link'],
            $rows
        );
    }
}

function mh_grid_transactions_payload(): array
{
    $username = mh_grid_dashboard_current_user();
    $tenantId = mh_grid_dashboard_current_tenant_id();
    if ($username === '' || $tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $filters = mh_grid_transactions_filters();
    $db = mh_grid_dashboard_db();
    $session = mh_grid_dashboard_active_session($db, $tenantId);
    $accounts = mh_grid_dashboard_settlement_accounts($db, $tenantId);

    $quotePage = mh_grid_transactions_quote_rows(
        $db,
        $tenantId,
        $filters,
        (int)$filters['quotePageSize'],
        ((int)$filters['quotePage'] - 1) * (int)$filters['quotePageSize']
    );
    $quoteTotalPages = max(1, (int)ceil(((int)$quotePage['total']) / max(1, (int)$filters['quotePageSize'])));
    $quotePagination = [
        'page' => min((int)$filters['quotePage'], $quoteTotalPages),
        'pageSize' => (int)$filters['quotePageSize'],
        'total' => (int)$quotePage['total'],
        'totalPages' => $quoteTotalPages,
        'hasPrev' => (int)$filters['quotePage'] > 1,
        'hasNext' => (int)$filters['quotePage'] < $quoteTotalPages,
    ];

    $allQuotesData = mh_grid_transactions_quote_rows($db, $tenantId, $filters, 500, 0);
    $allQuotes = $allQuotesData['rows'];

    $ledger = [];
    try {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        if ($pdoTok instanceof PDO) {
            $tokenSummary = mh_grid_dashboard_token_summary($pdoTok, $username, 500);
            $ledger = is_array($tokenSummary['recentLedger'] ?? null) ? $tokenSummary['recentLedger'] : [];
        }
    } catch (Throwable) {
        $ledger = [];
    }

    $activityAll = mh_grid_transactions_filter_activity(mh_grid_transactions_activity_rows($allQuotes, $ledger), $filters);
    $activityPage = mh_grid_transactions_paginate($activityAll, (int)$filters['activityPage'], (int)$filters['activityPageSize']);

    $counts = [
        'settlement' => 0,
        'platform' => 0,
    ];
    foreach ($activityAll as $item) {
        $kind = trim((string)($item['kind'] ?? ''));
        if ($kind !== '' && array_key_exists($kind, $counts)) {
            $counts[$kind]++;
        }
    }

    return [
        'ok' => true,
        'tenantId' => $tenantId,
        'username' => $username,
        'filters' => $filters,
        'quotes' => $quotePage['rows'],
        'quotePagination' => $quotePagination,
        'activity' => $activityPage['rows'],
        'activityPagination' => $activityPage['pagination'],
        'counts' => [
            'settlement' => $counts['settlement'],
            'platform' => $counts['platform'],
            'quotesLoaded' => (int)$quotePage['total'],
            'accountingRows' => count($activityAll),
        ],
        'accountId' => $accounts !== [] ? (string)$accounts[0]['accountId'] : '',
        'session' => $session,
        'exports' => [
            'quotesCsv' => mh_grid_transactions_export_url($filters, 'quotes_csv'),
            'accountingCsv' => mh_grid_transactions_export_url($filters, 'accounting_csv'),
        ],
        'links' => [
            'dashboard' => '/hub/grid/dashboard.php',
            'payments' => '/hub/grid/payments.php',
            'passkey' => '/hub/grid/passkey.php',
            'tokens' => '/hub/tokens/tokens.php',
        ],
    ];
}

if (mh_grid_dashboard_current_user() === '') {
    mh_grid_dashboard_json(401, ['ok' => false, 'error' => 'auth_required']);
}

try {
    $export = strtolower(mh_grid_transactions_string_param('export'));
    if ($export !== '') {
        mh_grid_transactions_export(mh_grid_transactions_filters());
    }
    mh_grid_dashboard_json(200, mh_grid_transactions_payload());
} catch (Throwable $e) {
    $status = $e->getMessage() === 'auth_required' ? 401 : 500;
    mh_grid_dashboard_json($status, [
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
