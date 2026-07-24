<?php
declare(strict_types=1);

function mh_finance_tenant_safe(string $tenantId): string
{
    $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($tenantId) : '';
    if (!is_string($tenantSafe) || trim($tenantSafe) === '') {
        $tenantSafe = preg_replace('/[^a-zA-Z0-9:_-]/', '_', $tenantId);
        $tenantSafe = preg_replace('/_+/', '_', (string)$tenantSafe);
        $tenantSafe = trim((string)$tenantSafe, '_');
    }
    if ($tenantSafe === '') {
        $tenantSafe = substr(hash('sha256', $tenantId), 0, 16);
    }
    return $tenantSafe;
}

function mh_finance_tenant_root(string $tenantId): string
{
    return '/data/tenants/' . mh_finance_tenant_safe($tenantId);
}

function mh_finance_ensure_tenant_paths(string $tenantId): array
{
    $root = mh_finance_tenant_root($tenantId);
    $paths = [
        'accounting_root' => $root . '/accounting',
        'accounting_receipts' => $root . '/accounting/receipts',
        'accounting_invoices' => $root . '/accounting/invoices',
        'accounting_exports_board' => $root . '/accounting/exports/board',
        'automation_state' => $root . '/automation/state',
        'automation_audit' => $root . '/automation/audit',
    ];

    $results = [];
    foreach ($paths as $key => $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
        $results[$key] = [
            'path' => $path,
            'created_or_exists' => is_dir($path),
        ];
    }
    return $results;
}

function mh_finance_ensure_column(PDO $db, string $table, string $column, string $definition): void
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $exists = (int)$stmt->fetchColumn() > 0;
    if ($exists) {
        return;
    }

    $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function mh_finance_ensure_tables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finance_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            event_key VARCHAR(255) NOT NULL,
            source_system VARCHAR(64) NOT NULL,
            source_type VARCHAR(64) NOT NULL,
            source_id VARCHAR(191) NULL,
            event_type VARCHAR(128) NOT NULL,
            event_status VARCHAR(64) NOT NULL DEFAULT 'captured',
            posting_status VARCHAR(64) NOT NULL DEFAULT 'pending',
            amount_decimal DECIMAL(20,8) NULL,
            currency VARCHAR(16) NULL,
            occurred_at_utc DATETIME NOT NULL,
            receipt_path VARCHAR(1024) NULL,
            receipt_sha256 CHAR(64) NULL,
            normalized_json LONGTEXT NULL,
            raw_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_finance_events_event_key (event_key),
            KEY idx_mh_finance_events_tenant_time (tenant_id, occurred_at_utc),
            KEY idx_mh_finance_events_source (tenant_id, source_system, source_type, source_id),
            KEY idx_mh_finance_events_posting (tenant_id, posting_status, occurred_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finance_event_exceptions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            event_key VARCHAR(255) NOT NULL,
            exception_type VARCHAR(128) NOT NULL,
            message TEXT NOT NULL,
            context_json LONGTEXT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'open',
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_mh_finance_event_exceptions_tenant (tenant_id, status, created_at_utc),
            KEY idx_mh_finance_event_exceptions_event (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finance_journal_entries (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            event_key VARCHAR(255) NOT NULL,
            entry_key VARCHAR(255) NOT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'posted',
            acceptance_status VARCHAR(64) NOT NULL DEFAULT 'pending',
            currency VARCHAR(16) NULL,
            occurred_at_utc DATETIME NOT NULL,
            memo VARCHAR(255) NULL,
            lines_json LONGTEXT NULL,
            approval_json LONGTEXT NULL,
            dispute_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_finance_journal_entries_entry_key (entry_key),
            KEY idx_mh_finance_journal_entries_tenant_time (tenant_id, occurred_at_utc),
            KEY idx_mh_finance_journal_entries_event (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finance_journal_lines (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            entry_key VARCHAR(255) NOT NULL,
            line_no INT NOT NULL,
            account_code VARCHAR(128) NOT NULL,
            direction VARCHAR(8) NOT NULL,
            amount_decimal DECIMAL(20,8) NOT NULL,
            currency VARCHAR(16) NULL,
            memo VARCHAR(255) NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mh_finance_journal_lines_entry (entry_key, line_no),
            KEY idx_mh_finance_journal_lines_tenant_account (tenant_id, account_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finance_reconciliation_runs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            run_key VARCHAR(255) NOT NULL,
            run_type VARCHAR(64) NOT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'completed',
            summary_json LONGTEXT NULL,
            artifact_path VARCHAR(1024) NULL,
            artifact_sha256 CHAR(64) NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_finance_reconciliation_runs_run_key (run_key),
            KEY idx_mh_finance_reconciliation_runs_tenant (tenant_id, created_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS mh_finance_board_exports (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(191) NOT NULL,
            export_key VARCHAR(255) NOT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'generated',
            as_of_utc DATETIME NOT NULL,
            export_root VARCHAR(1024) NOT NULL,
            manifest_path VARCHAR(1024) NULL,
            manifest_sha256 CHAR(64) NULL,
            summary_json LONGTEXT NULL,
            created_at_utc DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_finance_board_exports_export_key (export_key),
            KEY idx_mh_finance_board_exports_tenant (tenant_id, created_at_utc)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    mh_finance_ensure_column($db, 'mh_finance_event_exceptions', 'approval_json', 'LONGTEXT NULL AFTER status');
    mh_finance_ensure_column($db, 'mh_finance_event_exceptions', 'dispute_json', 'LONGTEXT NULL AFTER approval_json');
    mh_finance_ensure_column($db, 'mh_finance_journal_entries', 'acceptance_status', "VARCHAR(64) NOT NULL DEFAULT 'pending' AFTER status");
    mh_finance_ensure_column($db, 'mh_finance_journal_entries', 'approval_json', 'LONGTEXT NULL AFTER lines_json');
    mh_finance_ensure_column($db, 'mh_finance_journal_entries', 'dispute_json', 'LONGTEXT NULL AFTER approval_json');
}

function mh_finance_parse_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return gmdate('Y-m-d H:i:s');
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return gmdate('Y-m-d H:i:s');
    }
    return gmdate('Y-m-d H:i:s', $ts);
}

function mh_finance_receipts_day_path(string $tenantId, string $occurredAtUtc): string
{
    $day = substr($occurredAtUtc, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        $day = gmdate('Y-m-d');
    }
    return mh_finance_tenant_root($tenantId) . '/accounting/receipts/' . $day;
}

function mh_finance_write_json_artifact(string $path, array $payload): array
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('finance_json_encode_failed');
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) == false) {
        throw new RuntimeException('finance_artifact_write_failed');
    }
    return [
        'path' => $path,
        'sha256' => hash('sha256', $json),
    ];
}

function mh_finance_json_encode(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function mh_finance_append_audit_line(string $tenantId, array $payload): void
{
    $auditDir = mh_finance_tenant_root($tenantId) . '/automation/audit';
    if (!is_dir($auditDir)) {
        @mkdir($auditDir, 0775, true);
    }
    $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        return;
    }
    @file_put_contents($auditDir . '/finance-events.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function mh_finance_record_exception(PDO $db, string $tenantId, string $eventKey, string $type, string $message, array $context = []): void
{
    mh_finance_ensure_tables($db);
    $stmt = $db->prepare("
        INSERT INTO mh_finance_event_exceptions
            (tenant_id, event_key, exception_type, message, context_json, status, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP())
    ");
    $stmt->execute([
        $tenantId,
        $eventKey,
        $type,
        $message,
        mh_finance_json_encode($context),
    ]);
    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.exception',
        'eventKey' => $eventKey,
        'exceptionType' => $type,
        'message' => $message,
        'context' => $context,
        'loggedAtUtc' => gmdate('c'),
    ]);
}

function mh_finance_post_event(PDO $db, array $event): array
{
    $tenantId = trim((string)($event['tenantId'] ?? ''));
    $eventKey = trim((string)($event['eventKey'] ?? ''));
    if ($tenantId === '' || $eventKey === '') {
        throw new InvalidArgumentException('finance_event_identity_required');
    }

    mh_finance_ensure_tenant_paths($tenantId);
    mh_finance_ensure_tables($db);

    $occurredAtUtc = mh_finance_parse_datetime((string)($event['occurredAtUtc'] ?? ''));
    $receiptDir = mh_finance_receipts_day_path($tenantId, $occurredAtUtc);
    $receiptSafe = preg_replace('/[^A-Za-z0-9:_-]/', '_', $eventKey);
    $receiptSafe = trim((string)$receiptSafe, '_');
    if ($receiptSafe === '') {
        $receiptSafe = 'finance_event_' . substr(hash('sha256', $eventKey), 0, 16);
    }

    $artifactPayload = [
        'tenantId' => $tenantId,
        'eventKey' => $eventKey,
        'eventType' => (string)($event['eventType'] ?? ''),
        'eventStatus' => (string)($event['eventStatus'] ?? 'captured'),
        'postingStatus' => (string)($event['postingStatus'] ?? 'pending'),
        'occurredAtUtc' => $occurredAtUtc,
        'sourceSystem' => (string)($event['sourceSystem'] ?? ''),
        'sourceType' => (string)($event['sourceType'] ?? ''),
        'sourceId' => (string)($event['sourceId'] ?? ''),
        'amount' => $event['amount'] ?? null,
        'currency' => $event['currency'] ?? null,
        'normalized' => is_array($event['normalized'] ?? null) ? $event['normalized'] : [],
        'raw' => is_array($event['raw'] ?? null) ? $event['raw'] : [],
        'recordedAtUtc' => gmdate('c'),
    ];
    $artifact = mh_finance_write_json_artifact($receiptDir . '/' . $receiptSafe . '.json', $artifactPayload);

    $amount = $event['amount'] ?? null;
    $amountDecimal = is_numeric($amount) ? number_format((float)$amount, 8, '.', '') : null;
    $normalizedJson = mh_finance_json_encode(is_array($event['normalized'] ?? null) ? $event['normalized'] : []);
    $rawJson = mh_finance_json_encode(is_array($event['raw'] ?? null) ? $event['raw'] : []);

    $stmt = $db->prepare("
        INSERT INTO mh_finance_events
            (tenant_id, event_key, source_system, source_type, source_id, event_type, event_status, posting_status, amount_decimal, currency, occurred_at_utc, receipt_path, receipt_sha256, normalized_json, raw_json, created_at_utc, updated_at_utc)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            event_status = VALUES(event_status),
            posting_status = VALUES(posting_status),
            amount_decimal = COALESCE(VALUES(amount_decimal), amount_decimal),
            currency = COALESCE(VALUES(currency), currency),
            occurred_at_utc = VALUES(occurred_at_utc),
            receipt_path = VALUES(receipt_path),
            receipt_sha256 = VALUES(receipt_sha256),
            normalized_json = VALUES(normalized_json),
            raw_json = VALUES(raw_json),
            updated_at_utc = UTC_TIMESTAMP()
    ");
    $stmt->execute([
        $tenantId,
        $eventKey,
        (string)($event['sourceSystem'] ?? 'unknown'),
        (string)($event['sourceType'] ?? 'unknown'),
        ($event['sourceId'] ?? null) !== null ? trim((string)$event['sourceId']) : null,
        (string)($event['eventType'] ?? 'finance.event'),
        (string)($event['eventStatus'] ?? 'captured'),
        (string)($event['postingStatus'] ?? 'pending'),
        $amountDecimal,
        ($event['currency'] ?? null) !== null ? trim((string)$event['currency']) : null,
        $occurredAtUtc,
        $artifact['path'],
        $artifact['sha256'],
        is_string($normalizedJson) ? $normalizedJson : '{}',
        is_string($rawJson) ? $rawJson : '{}',
    ]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.event',
        'eventKey' => $eventKey,
        'eventType' => (string)($event['eventType'] ?? 'finance.event'),
        'sourceSystem' => (string)($event['sourceSystem'] ?? 'unknown'),
        'sourceType' => (string)($event['sourceType'] ?? 'unknown'),
        'sourceId' => (string)($event['sourceId'] ?? ''),
        'receiptPath' => $artifact['path'],
        'receiptSha256' => $artifact['sha256'],
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'tenantId' => $tenantId,
        'eventKey' => $eventKey,
        'receiptPath' => $artifact['path'],
        'receiptSha256' => $artifact['sha256'],
        'occurredAtUtc' => $occurredAtUtc,
    ];
}

function mh_finance_decode_json_row(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_finance_pending_events(PDO $db, ?string $tenantId = null, int $limit = 100): array
{
    mh_finance_ensure_tables($db);
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT *
        FROM mh_finance_events
        WHERE posting_status = 'pending'
    ";
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $sql .= " AND tenant_id = ? ";
        $params[] = trim($tenantId);
    }
    $sql .= " ORDER BY occurred_at_utc ASC, id ASC LIMIT " . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_finance_event_posting_template(array $eventRow): array
{
    $normalized = mh_finance_decode_json_row($eventRow['normalized_json'] ?? null);
    $eventType = trim((string)($eventRow['event_type'] ?? ''));
    $amount = isset($eventRow['amount_decimal']) && is_numeric($eventRow['amount_decimal'])
        ? (float)$eventRow['amount_decimal']
        : null;
    $currency = trim((string)($eventRow['currency'] ?? ''));
    $sourceType = strtoupper(trim((string)($normalized['sourceType'] ?? '')));
    $destinationType = strtoupper(trim((string)($normalized['destinationType'] ?? '')));

    if ($amount === null || $amount <= 0) {
        throw new RuntimeException('finance_event_missing_amount');
    }
    if ($currency === '') {
        throw new RuntimeException('finance_event_missing_currency');
    }

    $memo = $eventType !== '' ? $eventType : 'finance.event';
    $lines = [];
    if (str_starts_with($eventType, 'grid.transaction.')) {
        $cashAccount = 'asset:cash:grid';
        if ($sourceType === 'ACCOUNT') {
            $lines = [
                ['account_code' => 'settlement:outgoing_suspense', 'direction' => 'debit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
                ['account_code' => $cashAccount, 'direction' => 'credit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
            ];
        } elseif ($destinationType === 'ACCOUNT') {
            $lines = [
                ['account_code' => $cashAccount, 'direction' => 'debit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
                ['account_code' => 'settlement:incoming_suspense', 'direction' => 'credit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
            ];
        } else {
            $lines = [
                ['account_code' => 'settlement:unknown_direction', 'direction' => 'debit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
                ['account_code' => 'settlement:unknown_offset', 'direction' => 'credit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
            ];
        }
    } else {
        $lines = [
            ['account_code' => 'finance:unclassified_event', 'direction' => 'debit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
            ['account_code' => 'finance:unclassified_offset', 'direction' => 'credit', 'amount_decimal' => $amount, 'currency' => $currency, 'memo' => $memo],
        ];
    }

    return [
        'memo' => $memo,
        'currency' => $currency,
        'lines' => $lines,
    ];
}

function mh_finance_post_journal_entry(PDO $db, array $eventRow): array
{
    mh_finance_ensure_tables($db);

    $tenantId = trim((string)($eventRow['tenant_id'] ?? ''));
    $eventKey = trim((string)($eventRow['event_key'] ?? ''));
    if ($tenantId === '' || $eventKey === '') {
        throw new RuntimeException('finance_event_row_invalid');
    }

    $template = mh_finance_event_posting_template($eventRow);
    $entryKey = 'journal:' . hash('sha256', $eventKey);
    $occurredAtUtc = mh_finance_parse_datetime((string)($eventRow['occurred_at_utc'] ?? ''));
    $linesJson = mh_finance_json_encode($template['lines']);

    $db->beginTransaction();
    try {
        $entryStmt = $db->prepare("
            INSERT INTO mh_finance_journal_entries
                (tenant_id, event_key, entry_key, status, acceptance_status, currency, occurred_at_utc, memo, lines_json, approval_json, dispute_json, created_at_utc, updated_at_utc)
            VALUES
                (?, ?, ?, 'posted', 'pending', ?, ?, ?, ?, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                currency = VALUES(currency),
                occurred_at_utc = VALUES(occurred_at_utc),
                memo = VALUES(memo),
                lines_json = VALUES(lines_json),
                updated_at_utc = UTC_TIMESTAMP()
        ");
        $entryStmt->execute([
            $tenantId,
            $eventKey,
            $entryKey,
            $template['currency'],
            $occurredAtUtc,
            $template['memo'],
            is_string($linesJson) ? $linesJson : '[]',
        ]);

        $deleteStmt = $db->prepare("DELETE FROM mh_finance_journal_lines WHERE entry_key = ?");
        $deleteStmt->execute([$entryKey]);

        $lineStmt = $db->prepare("
            INSERT INTO mh_finance_journal_lines
                (tenant_id, entry_key, line_no, account_code, direction, amount_decimal, currency, memo, created_at_utc)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ");
        foreach (array_values($template['lines']) as $index => $line) {
            $lineStmt->execute([
                $tenantId,
                $entryKey,
                $index + 1,
                (string)$line['account_code'],
                (string)$line['direction'],
                number_format((float)$line['amount_decimal'], 8, '.', ''),
                (string)$line['currency'],
                (string)$line['memo'],
            ]);
        }

        $updateEvent = $db->prepare("
            UPDATE mh_finance_events
            SET posting_status = 'posted',
                updated_at_utc = UTC_TIMESTAMP()
            WHERE event_key = ?
        ");
        $updateEvent->execute([$eventKey]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.journal.posted',
        'eventKey' => $eventKey,
        'entryKey' => $entryKey,
        'currency' => $template['currency'],
        'memo' => $template['memo'],
        'acceptanceStatus' => 'pending',
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'tenantId' => $tenantId,
        'eventKey' => $eventKey,
        'entryKey' => $entryKey,
        'currency' => $template['currency'],
        'acceptanceStatus' => 'pending',
        'lineCount' => count($template['lines']),
    ];
}

function mh_finance_process_pending_events(PDO $db, ?string $tenantId = null, int $limit = 100): array
{
    $events = mh_finance_pending_events($db, $tenantId, $limit);
    $processed = 0;
    $exceptions = 0;
    $entries = [];

    foreach ($events as $eventRow) {
        if (!is_array($eventRow)) {
            continue;
        }
        try {
            $entries[] = mh_finance_post_journal_entry($db, $eventRow);
            $processed += 1;
        } catch (Throwable $e) {
            $eventKey = trim((string)($eventRow['event_key'] ?? ''));
            $eventTenantId = trim((string)($eventRow['tenant_id'] ?? ''));
            if ($eventKey !== '' && $eventTenantId !== '') {
                $upd = $db->prepare("
                    UPDATE mh_finance_events
                    SET posting_status = 'exception',
                        updated_at_utc = UTC_TIMESTAMP()
                    WHERE event_key = ?
                ");
                $upd->execute([$eventKey]);
                mh_finance_record_exception(
                    $db,
                    $eventTenantId,
                    $eventKey,
                    'journal_post_failed',
                    $e->getMessage(),
                    ['eventType' => (string)($eventRow['event_type'] ?? '')]
                );
            }
            $exceptions += 1;
        }
    }

    return [
        'selected' => count($events),
        'processed' => $processed,
        'exceptions' => $exceptions,
        'entries' => $entries,
    ];
}

function mh_finance_counts(PDO $db, ?string $tenantId = null): array
{
    mh_finance_ensure_tables($db);
    $where = '';
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $where = ' WHERE tenant_id = ? ';
        $params[] = trim($tenantId);
    }

    $counts = [
        'pending' => 0,
        'posted' => 0,
        'exception' => 0,
        'journalEntries' => 0,
        'openExceptions' => 0,
    ];

    $stmt = $db->prepare("SELECT posting_status, COUNT(*) AS c FROM mh_finance_events {$where} GROUP BY posting_status");
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $status = trim((string)($row['posting_status'] ?? ''));
        $count = (int)($row['c'] ?? 0);
        if ($status === 'pending') $counts['pending'] = $count;
        if ($status === 'posted') $counts['posted'] = $count;
        if ($status === 'exception') $counts['exception'] = $count;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM mh_finance_journal_entries" . ($where !== '' ? " WHERE tenant_id = ? " : ''));
    $stmt->execute($params);
    $counts['journalEntries'] = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM mh_finance_event_exceptions" . ($where !== '' ? " WHERE tenant_id = ? AND status = 'open' " : " WHERE status = 'open' "));
    $stmt->execute($params);
    $counts['openExceptions'] = (int)$stmt->fetchColumn();

    return $counts;
}

function mh_finance_recent_events(PDO $db, ?string $tenantId = null, int $limit = 50): array
{
    mh_finance_ensure_tables($db);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT * FROM mh_finance_events";
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $sql .= " WHERE tenant_id = ? ";
        $params[] = trim($tenantId);
    }
    $sql .= " ORDER BY occurred_at_utc DESC, id DESC LIMIT " . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_finance_recent_exceptions(PDO $db, ?string $tenantId = null, int $limit = 50): array
{
    mh_finance_ensure_tables($db);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT * FROM mh_finance_event_exceptions";
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $sql .= " WHERE tenant_id = ? ";
        $params[] = trim($tenantId);
    }
    $sql .= " ORDER BY created_at_utc DESC, id DESC LIMIT " . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_finance_exception_row(PDO $db, string $tenantId, int $exceptionId): ?array
{
    mh_finance_ensure_tables($db);
    $stmt = $db->prepare("
        SELECT *
        FROM mh_finance_event_exceptions
        WHERE tenant_id = ? AND id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $exceptionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_finance_requeue_exception(PDO $db, string $tenantId, int $exceptionId, array $actor = []): array
{
    $tenantId = trim($tenantId);
    if ($tenantId === '' || $exceptionId <= 0) {
        throw new InvalidArgumentException('finance_exception_requeue_identity_required');
    }

    $row = mh_finance_exception_row($db, $tenantId, $exceptionId);
    if (!is_array($row)) {
        throw new RuntimeException('finance_exception_not_found');
    }

    $eventKey = trim((string)($row['event_key'] ?? ''));
    if ($eventKey === '') {
        throw new RuntimeException('finance_exception_event_key_missing');
    }

    $db->beginTransaction();
    try {
        $eventUpd = $db->prepare("
            UPDATE mh_finance_events
            SET posting_status = 'pending',
                updated_at_utc = UTC_TIMESTAMP()
            WHERE tenant_id = ? AND event_key = ?
        ");
        $eventUpd->execute([$tenantId, $eventKey]);

        $exceptionUpd = $db->prepare("
            UPDATE mh_finance_event_exceptions
            SET status = 'requeued',
                updated_at_utc = UTC_TIMESTAMP()
            WHERE tenant_id = ? AND id = ?
        ");
        $exceptionUpd->execute([$tenantId, $exceptionId]);
        $db->commit();

        mh_finance_append_audit_line($tenantId, [
            'kind' => 'finance.exception.requeued',
            'exceptionId' => $exceptionId,
            'eventKey' => $eventKey,
            'actor' => $actor,
            'recordedAtUtc' => gmdate('c'),
        ]);

        return [
            'tenantId' => $tenantId,
            'exceptionId' => $exceptionId,
            'eventKey' => $eventKey,
            'eventRowsUpdated' => $eventUpd->rowCount(),
            'exceptionRowsUpdated' => $exceptionUpd->rowCount(),
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function mh_finance_resolve_exception(PDO $db, string $tenantId, int $exceptionId, string $status, array $actor = [], ?string $note = null): array
{
    $tenantId = trim($tenantId);
    $status = strtolower(trim($status));
    if ($tenantId === '' || $exceptionId <= 0) {
        throw new InvalidArgumentException('finance_exception_resolution_identity_required');
    }
    if ($status !== 'resolved' && $status !== 'ignored') {
        throw new InvalidArgumentException('finance_exception_resolution_status_invalid');
    }

    $row = mh_finance_exception_row($db, $tenantId, $exceptionId);
    if (!is_array($row)) {
        throw new RuntimeException('finance_exception_not_found');
    }

    $stmt = $db->prepare("
        UPDATE mh_finance_event_exceptions
        SET status = ?,
            approval_json = ?,
            updated_at_utc = UTC_TIMESTAMP()
        WHERE tenant_id = ? AND id = ?
    ");
    $approval = [
        'decision' => $status,
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ];
    $stmt->execute([$status, mh_finance_json_encode($approval), $tenantId, $exceptionId]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.exception.resolved',
        'exceptionId' => $exceptionId,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'status' => $status,
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'tenantId' => $tenantId,
        'exceptionId' => $exceptionId,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'status' => $status,
        'rowsUpdated' => $stmt->rowCount(),
    ];
}

function mh_finance_dispute_exception(PDO $db, string $tenantId, int $exceptionId, array $actor = [], ?string $note = null): array
{
    $tenantId = trim($tenantId);
    if ($tenantId === '' || $exceptionId <= 0) {
        throw new InvalidArgumentException('finance_exception_dispute_identity_required');
    }

    $row = mh_finance_exception_row($db, $tenantId, $exceptionId);
    if (!is_array($row)) {
        throw new RuntimeException('finance_exception_not_found');
    }

    $dispute = [
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ];

    $stmt = $db->prepare("
        UPDATE mh_finance_event_exceptions
        SET status = 'disputed',
            dispute_json = ?,
            updated_at_utc = UTC_TIMESTAMP()
        WHERE tenant_id = ? AND id = ?
    ");
    $stmt->execute([mh_finance_json_encode($dispute), $tenantId, $exceptionId]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.exception.disputed',
        'exceptionId' => $exceptionId,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'tenantId' => $tenantId,
        'exceptionId' => $exceptionId,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'status' => 'disputed',
        'rowsUpdated' => $stmt->rowCount(),
    ];
}

function mh_finance_journal_entry_row(PDO $db, string $tenantId, string $entryKey): ?array
{
    mh_finance_ensure_tables($db);
    $stmt = $db->prepare("
        SELECT *
        FROM mh_finance_journal_entries
        WHERE tenant_id = ? AND entry_key = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $entryKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_finance_accept_journal_entry(PDO $db, string $tenantId, string $entryKey, array $actor = [], ?string $note = null): array
{
    $tenantId = trim($tenantId);
    $entryKey = trim($entryKey);
    if ($tenantId === '' || $entryKey === '') {
        throw new InvalidArgumentException('finance_journal_accept_identity_required');
    }

    $row = mh_finance_journal_entry_row($db, $tenantId, $entryKey);
    if (!is_array($row)) {
        throw new RuntimeException('finance_journal_not_found');
    }

    $approval = [
        'decision' => 'accepted',
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ];
    $stmt = $db->prepare("
        UPDATE mh_finance_journal_entries
        SET acceptance_status = 'accepted',
            approval_json = ?,
            updated_at_utc = UTC_TIMESTAMP()
        WHERE tenant_id = ? AND entry_key = ?
    ");
    $stmt->execute([mh_finance_json_encode($approval), $tenantId, $entryKey]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.journal.accepted',
        'entryKey' => $entryKey,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'tenantId' => $tenantId,
        'entryKey' => $entryKey,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'acceptanceStatus' => 'accepted',
        'rowsUpdated' => $stmt->rowCount(),
    ];
}

function mh_finance_dispute_journal_entry(PDO $db, string $tenantId, string $entryKey, array $actor = [], ?string $note = null): array
{
    $tenantId = trim($tenantId);
    $entryKey = trim($entryKey);
    if ($tenantId === '' || $entryKey === '') {
        throw new InvalidArgumentException('finance_journal_dispute_identity_required');
    }

    $row = mh_finance_journal_entry_row($db, $tenantId, $entryKey);
    if (!is_array($row)) {
        throw new RuntimeException('finance_journal_not_found');
    }

    $dispute = [
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ];
    $stmt = $db->prepare("
        UPDATE mh_finance_journal_entries
        SET acceptance_status = 'disputed',
            dispute_json = ?,
            updated_at_utc = UTC_TIMESTAMP()
        WHERE tenant_id = ? AND entry_key = ?
    ");
    $stmt->execute([mh_finance_json_encode($dispute), $tenantId, $entryKey]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.journal.disputed',
        'entryKey' => $entryKey,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'note' => $note,
        'actor' => $actor,
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'tenantId' => $tenantId,
        'entryKey' => $entryKey,
        'eventKey' => (string)($row['event_key'] ?? ''),
        'acceptanceStatus' => 'disputed',
        'rowsUpdated' => $stmt->rowCount(),
    ];
}

function mh_finance_recent_journal_entries(PDO $db, ?string $tenantId = null, int $limit = 50): array
{
    mh_finance_ensure_tables($db);
    $limit = max(1, min(200, $limit));
    $sql = "SELECT * FROM mh_finance_journal_entries";
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $sql .= " WHERE tenant_id = ? ";
        $params[] = trim($tenantId);
    }
    $sql .= " ORDER BY occurred_at_utc DESC, id DESC LIMIT " . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_finance_recent_reconciliation_runs(PDO $db, ?string $tenantId = null, int $limit = 25): array
{
    mh_finance_ensure_tables($db);
    $limit = max(1, min(100, $limit));
    $sql = "SELECT * FROM mh_finance_reconciliation_runs";
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $sql .= " WHERE tenant_id = ? ";
        $params[] = trim($tenantId);
    }
    $sql .= " ORDER BY created_at_utc DESC, id DESC LIMIT " . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_finance_recent_board_exports(PDO $db, ?string $tenantId = null, int $limit = 25): array
{
    mh_finance_ensure_tables($db);
    $limit = max(1, min(100, $limit));
    $sql = "SELECT * FROM mh_finance_board_exports";
    $params = [];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $sql .= " WHERE tenant_id = ? ";
        $params[] = trim($tenantId);
    }
    $sql .= " ORDER BY created_at_utc DESC, id DESC LIMIT " . $limit;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function mh_finance_csv_string(array $header, array $rows): string
{
    $fh = fopen('php://temp', 'r+');
    if ($fh === false) {
        throw new RuntimeException('finance_csv_open_failed');
    }
    fputcsv($fh, $header);
    foreach ($rows as $row) {
        $line = [];
        foreach ($header as $key) {
            $line[] = $row[$key] ?? '';
        }
        fputcsv($fh, $line);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    if (!is_string($csv)) {
        throw new RuntimeException('finance_csv_build_failed');
    }
    return $csv;
}

function mh_finance_write_text_artifact(string $path, string $contents): array
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (@file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('finance_artifact_write_failed');
    }
    return [
        'path' => $path,
        'sha256' => hash('sha256', $contents),
    ];
}

function mh_finance_reconciliation_snapshot(PDO $db, string $tenantId): array
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        throw new InvalidArgumentException('tenant_id_required');
    }
    mh_finance_ensure_tenant_paths($tenantId);
    mh_finance_ensure_tables($db);

    $counts = mh_finance_counts($db, $tenantId);
    $events = mh_finance_recent_events($db, $tenantId, 500);
    $journals = mh_finance_recent_journal_entries($db, $tenantId, 500);
    $exceptions = mh_finance_recent_exceptions($db, $tenantId, 500);

    $summary = [
        'tenantId' => $tenantId,
        'capturedAtUtc' => gmdate('c'),
        'counts' => $counts,
        'eventCount' => count($events),
        'journalCount' => count($journals),
        'exceptionCount' => count($exceptions),
    ];

    $runKey = 'reconcile:' . hash('sha256', $tenantId . '|' . gmdate('c'));
    $stateDir = mh_finance_tenant_root($tenantId) . '/automation/state';
    $artifact = mh_finance_write_json_artifact($stateDir . '/reconciliation-' . gmdate('Ymd-His') . '.json', $summary);

    $stmt = $db->prepare("
        INSERT INTO mh_finance_reconciliation_runs
            (tenant_id, run_key, run_type, status, summary_json, artifact_path, artifact_sha256, created_at_utc)
        VALUES
            (?, ?, 'snapshot', 'completed', ?, ?, ?, UTC_TIMESTAMP())
    ");
    $stmt->execute([
        $tenantId,
        $runKey,
        json_encode($summary, JSON_UNESCAPED_SLASHES),
        $artifact['path'],
        $artifact['sha256'],
    ]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.reconciliation.snapshot',
        'runKey' => $runKey,
        'artifactPath' => $artifact['path'],
        'artifactSha256' => $artifact['sha256'],
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'runKey' => $runKey,
        'artifactPath' => $artifact['path'],
        'artifactSha256' => $artifact['sha256'],
        'summary' => $summary,
    ];
}

function mh_finance_generate_board_pack(PDO $db, string $tenantId, ?string $asOfUtc = null): array
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        throw new InvalidArgumentException('tenant_id_required');
    }
    mh_finance_ensure_tenant_paths($tenantId);
    mh_finance_ensure_tables($db);

    $asOfUtc = mh_finance_parse_datetime($asOfUtc);
    $stamp = gmdate('Ymd-His', strtotime($asOfUtc));
    $root = mh_finance_tenant_root($tenantId) . '/accounting/exports/board/' . $stamp;
    if (!is_dir($root)) {
        @mkdir($root, 0775, true);
    }

    $counts = mh_finance_counts($db, $tenantId);
    $events = mh_finance_recent_events($db, $tenantId, 1000);
    $journals = mh_finance_recent_journal_entries($db, $tenantId, 1000);
    $exceptions = mh_finance_recent_exceptions($db, $tenantId, 1000);
    $reconciliations = mh_finance_recent_reconciliation_runs($db, $tenantId, 50);

    $summary = [
        'tenantId' => $tenantId,
        'asOfUtc' => gmdate('c', strtotime($asOfUtc)),
        'generatedAtUtc' => gmdate('c'),
        'counts' => $counts,
        'included' => [
            'financeEvents' => count($events),
            'journalEntries' => count($journals),
            'exceptions' => count($exceptions),
            'reconciliationRuns' => count($reconciliations),
        ],
    ];

    $summaryArtifact = mh_finance_write_json_artifact($root . '/summary.json', $summary);

    $eventsCsv = mh_finance_csv_string(
        ['occurred_at_utc', 'event_type', 'posting_status', 'amount_decimal', 'currency', 'source_system', 'source_id', 'receipt_path', 'event_key'],
        $events
    );
    $journalsCsv = mh_finance_csv_string(
        ['occurred_at_utc', 'entry_key', 'event_key', 'status', 'currency', 'memo'],
        $journals
    );
    $exceptionsCsv = mh_finance_csv_string(
        ['created_at_utc', 'event_key', 'exception_type', 'message', 'status'],
        $exceptions
    );

    $eventsArtifact = mh_finance_write_text_artifact($root . '/finance-events.csv', $eventsCsv);
    $journalsArtifact = mh_finance_write_text_artifact($root . '/journal-entries.csv', $journalsCsv);
    $exceptionsArtifact = mh_finance_write_text_artifact($root . '/exceptions.csv', $exceptionsCsv);

    $manifest = [
        'tenantId' => $tenantId,
        'exportRoot' => $root,
        'asOfUtc' => gmdate('c', strtotime($asOfUtc)),
        'generatedAtUtc' => gmdate('c'),
        'files' => [
            'summary' => $summaryArtifact,
            'financeEventsCsv' => $eventsArtifact,
            'journalEntriesCsv' => $journalsArtifact,
            'exceptionsCsv' => $exceptionsArtifact,
        ],
    ];
    $manifestArtifact = mh_finance_write_json_artifact($root . '/manifest.json', $manifest);

    $exportKey = 'boardpack:' . hash('sha256', $tenantId . '|' . $root);
    $stmt = $db->prepare("
        INSERT INTO mh_finance_board_exports
            (tenant_id, export_key, status, as_of_utc, export_root, manifest_path, manifest_sha256, summary_json, created_at_utc)
        VALUES
            (?, ?, 'generated', ?, ?, ?, ?, ?, UTC_TIMESTAMP())
    ");
    $stmt->execute([
        $tenantId,
        $exportKey,
        $asOfUtc,
        $root,
        $manifestArtifact['path'],
        $manifestArtifact['sha256'],
        json_encode($summary, JSON_UNESCAPED_SLASHES),
    ]);

    mh_finance_append_audit_line($tenantId, [
        'kind' => 'finance.boardpack.generated',
        'exportKey' => $exportKey,
        'exportRoot' => $root,
        'manifestPath' => $manifestArtifact['path'],
        'manifestSha256' => $manifestArtifact['sha256'],
        'recordedAtUtc' => gmdate('c'),
    ]);

    return [
        'exportKey' => $exportKey,
        'exportRoot' => $root,
        'manifestPath' => $manifestArtifact['path'],
        'manifestSha256' => $manifestArtifact['sha256'],
        'summary' => $summary,
    ];
}

function mh_finance_grid_quote_row(PDO $db, string $tenantId, string $quoteId): ?array
{
    $stmt = $db->prepare("
        SELECT *
        FROM mh_settlement_quotes
        WHERE tenant_id = ? AND sr_quote_id = ?
        LIMIT 1
    ");
    $stmt->execute([$tenantId, $quoteId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function mh_finance_grid_json_decode(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_finance_grid_amount_details(array $quoteRow, array $transaction): array
{
    $request = mh_finance_grid_json_decode($quoteRow['raw_request_json'] ?? null);
    $snapshot = mh_finance_grid_json_decode($quoteRow['raw_snapshot_json'] ?? null);

    $amount = null;
    $currency = '';

    $candidates = [
        $request['lockedCurrencyAmount'] ?? null,
        $snapshot['lockedCurrencyAmount'] ?? null,
        $transaction['amount'] ?? null,
        $transaction['amount']['value'] ?? null,
        $transaction['amount']['amount'] ?? null,
        $transaction['amount']['units'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_numeric($candidate)) {
            $amount = (float)$candidate;
            break;
        }
    }

    $currencyCandidates = [
        $request['destination']['currency'] ?? null,
        $request['source']['currency'] ?? null,
        $snapshot['destination']['currency'] ?? null,
        $snapshot['source']['currency'] ?? null,
        $transaction['amount']['currency'] ?? null,
        $transaction['currency'] ?? null,
    ];
    foreach ($currencyCandidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $currency = $candidate;
            break;
        }
    }

    return [
        'amount' => $amount,
        'currency' => $currency !== '' ? $currency : null,
    ];
}

function mh_finance_post_grid_terminal_event(PDO $db, string $tenantId, array $context): ?array
{
    $quoteId = trim((string)($context['quoteId'] ?? ''));
    $transactionId = trim((string)($context['transactionId'] ?? ''));
    $status = strtoupper(trim((string)($context['status'] ?? '')));
    if ($quoteId === '' || $transactionId === '' || $status === '') {
        return null;
    }

    $quoteRow = mh_finance_grid_quote_row($db, $tenantId, $quoteId);
    $transaction = isset($context['transaction']) && is_array($context['transaction']) ? $context['transaction'] : [];
    $money = mh_finance_grid_amount_details($quoteRow ?? [], $transaction);
    $occurredAtUtc = mh_finance_parse_datetime((string)($context['occurredAtUtc'] ?? ''));
    $sourceChannel = trim((string)($context['sourceChannel'] ?? 'grid'));
    $eventType = 'grid.transaction.' . strtolower($status);
    $eventKey = 'finance:grid:' . hash(
        'sha256',
        implode('|', [$tenantId, $quoteId, $transactionId, $status])
    );

    $normalized = [
        'tenantId' => $tenantId,
        'quoteId' => $quoteId,
        'transactionId' => $transactionId,
        'status' => $status,
        'sourceType' => trim((string)(($quoteRow['source_type'] ?? '') ?: ($context['sourceType'] ?? ''))),
        'destinationType' => trim((string)(($quoteRow['destination_type'] ?? '') ?: ($context['destinationType'] ?? ''))),
        'requiresGridWalletSignature' => (bool)($quoteRow['requires_grid_wallet_signature'] ?? false),
        'occurredAtUtc' => $occurredAtUtc,
        'sourceChannel' => $sourceChannel,
    ];

    return mh_finance_post_event($db, [
        'tenantId' => $tenantId,
        'eventKey' => $eventKey,
        'sourceSystem' => 'grid',
        'sourceType' => 'settlement_transaction',
        'sourceId' => $transactionId,
        'eventType' => $eventType,
        'eventStatus' => 'captured',
        'postingStatus' => 'pending',
        'amount' => $money['amount'],
        'currency' => $money['currency'],
        'occurredAtUtc' => $occurredAtUtc,
        'normalized' => $normalized,
        'raw' => [
            'transaction' => $transaction,
            'quote' => $quoteRow,
            'context' => $context,
        ],
    ]);
}
