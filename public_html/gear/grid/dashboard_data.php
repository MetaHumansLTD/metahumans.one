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
require_once __DIR__ . '/../../auth/tokenomics.php';
require_once __DIR__ . '/../../hub/equity/db.php';
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

function mh_grid_dashboard_json(int $status, array $payload): void
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

function mh_grid_dashboard_current_user(): string
{
    $user = $_SESSION['mh_auth_user'] ?? '';
    return is_string($user) ? trim($user) : '';
}

function mh_grid_dashboard_current_tenant_id(): string
{
    $tenantId = function_exists('mh_grid_current_tenant_id') ? mh_grid_current_tenant_id() : '';
    return is_string($tenantId) ? trim($tenantId) : '';
}

function mh_grid_dashboard_is_admin(): bool
{
    $role = $_SESSION['mh_auth_role'] ?? '';
    return is_string($role) && stripos($role, 'kripzmaster') !== false;
}

function mh_grid_dashboard_db(): PDO
{
    $db = mh_grid_get_db();
    if (!$db instanceof PDO) {
        throw new RuntimeException('db_unavailable');
    }
    mh_grid_ensure_tables($db);
    return $db;
}

function mh_grid_dashboard_json_decode(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_grid_dashboard_extract_currency(mixed $value): string
{
    if (is_string($value)) {
        return strtoupper(trim($value));
    }
    if (is_array($value)) {
        foreach (['code', 'currencyCode', 'symbol'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && trim($value[$key]) !== '') {
                return strtoupper(trim((string)$value[$key]));
            }
        }
    }
    return '';
}

function mh_grid_dashboard_extract_money(mixed $node, int $depth = 0): ?array
{
    if ($depth > 5 || !is_array($node)) {
        return null;
    }

    $amountKeys = ['amount', 'value', 'units', 'availableAmount'];
    foreach ($amountKeys as $amountKey) {
        if (!array_key_exists($amountKey, $node)) {
            continue;
        }
        $rawAmount = $node[$amountKey];
        if (is_numeric($rawAmount)) {
            $currency = '';
            foreach (['currency', 'currencyCode', 'code', 'assetCode'] as $currencyKey) {
                if (isset($node[$currencyKey])) {
                    $currency = mh_grid_dashboard_extract_currency($node[$currencyKey]);
                    if ($currency !== '') {
                        break;
                    }
                }
            }
            return [
                'amount' => (float)$rawAmount,
                'currency' => $currency,
            ];
        }
    }

    foreach (['availableBalance', 'balance', 'currentBalance', 'totalBalance', 'walletBalance', 'balances', 'availableFunds'] as $key) {
        if (!isset($node[$key])) {
            continue;
        }
        $candidate = $node[$key];
        if (is_array($candidate)) {
            if (array_is_list($candidate)) {
                foreach ($candidate as $item) {
                    $parsed = mh_grid_dashboard_extract_money($item, $depth + 1);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            } else {
                $parsed = mh_grid_dashboard_extract_money($candidate, $depth + 1);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            $parsed = mh_grid_dashboard_extract_money($value, $depth + 1);
            if ($parsed !== null) {
                return $parsed;
            }
        }
    }

    return null;
}

function mh_grid_dashboard_format_money(?float $amount, string $currency = ''): string
{
    if ($amount === null) {
        return 'Unavailable';
    }

    $currency = strtoupper(trim($currency));
    $formatted = number_format($amount, 2);
    if ($currency === 'USD' || $currency === 'USDC' || $currency === 'USDT') {
        return $currency . ' ' . $formatted;
    }
    return ($currency !== '' ? ($currency . ' ') : '') . $formatted;
}

function mh_grid_dashboard_platform_summary(array $cfg): array
{
    $baseUrl = isset($cfg['base_url']) && is_string($cfg['base_url']) ? trim($cfg['base_url']) : '';
    $credsSet = isset($cfg['token_id'], $cfg['client_secret'])
        && is_string($cfg['token_id'])
        && is_string($cfg['client_secret'])
        && trim($cfg['token_id']) !== ''
        && trim($cfg['client_secret']) !== '';
    $summary = [
        'baseUrl' => $baseUrl,
        'baseUrlSet' => $baseUrl !== '',
        'credentialsSet' => $credsSet,
        'webhookKeySet' => isset($cfg['webhook_public_key_pem']) && is_string($cfg['webhook_public_key_pem']) && trim($cfg['webhook_public_key_pem']) !== '',
        'allowlistCount' => isset($cfg['allowlist']) && is_array($cfg['allowlist']) ? count($cfg['allowlist']) : 0,
        'configReachable' => false,
        'environment' => 'unknown',
        'umaDomain' => '',
        'proxyUmaSubdomain' => '',
        'webhookEndpoint' => '',
        'appName' => '',
        'notes' => [],
    ];

    if (!$credsSet) {
        $summary['notes'][] = 'Platform credentials are not configured.';
        return $summary;
    }

    try {
        $resp = mh_grid_http_request($cfg, 'GET', '/config');
        if (($resp['ok'] ?? false) !== true || !is_array($resp['json'] ?? null)) {
            $summary['notes'][] = 'Live /config lookup failed.';
            return $summary;
        }
        $json = $resp['json'];
        $summary['configReachable'] = true;
        $summary['umaDomain'] = trim((string)($json['umaDomain'] ?? ''));
        $summary['proxyUmaSubdomain'] = trim((string)($json['proxyUmaSubdomain'] ?? ''));
        $summary['webhookEndpoint'] = trim((string)($json['webhookEndpoint'] ?? ''));
        $embedded = isset($json['embeddedWalletConfig']) && is_array($json['embeddedWalletConfig']) ? $json['embeddedWalletConfig'] : [];
        $summary['appName'] = trim((string)($embedded['appName'] ?? ''));
        $environmentSignals = strtolower($summary['umaDomain'] . ' ' . $summary['proxyUmaSubdomain'] . ' ' . $summary['appName']);
        $summary['environment'] = str_contains($environmentSignals, 'sandbox') ? 'sandbox' : 'production';
        if ($summary['environment'] === 'sandbox') {
            $summary['notes'][] = 'Cards, ramps, rewards, and real OTP delivery remain gated until production enablement.';
        }
    } catch (Throwable $e) {
        $summary['notes'][] = 'Live /config lookup failed: ' . $e->getMessage();
    }

    return $summary;
}

function mh_grid_dashboard_settlement_accounts(PDO $db, string $tenantId): array
{
    $stmt = $db->prepare("
        SELECT sr_internal_account_id, account_type, currency, label, status, raw_snapshot_json, updated_at_utc, created_at_utc
        FROM mh_settlement_accounts
        WHERE tenant_id = ?
        ORDER BY updated_at_utc DESC, created_at_utc DESC, id DESC
    ");
    $stmt->execute([$tenantId]);

    $accounts = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $snapshot = mh_grid_dashboard_json_decode($row['raw_snapshot_json'] ?? '');
        $money = mh_grid_dashboard_extract_money($snapshot);
        $currency = trim((string)($row['currency'] ?? ''));
        if ($currency === '' && $money !== null) {
            $currency = trim((string)($money['currency'] ?? ''));
        }
        $accounts[] = [
            'accountId' => trim((string)($row['sr_internal_account_id'] ?? '')),
            'accountType' => trim((string)($row['account_type'] ?? '')),
            'currency' => $currency,
            'label' => trim((string)($row['label'] ?? '')) ?: 'Settlement Account',
            'status' => trim((string)($row['status'] ?? 'unknown')),
            'balanceAmount' => $money !== null ? (float)$money['amount'] : null,
            'balanceCurrency' => $money !== null ? trim((string)($money['currency'] ?? $currency)) : $currency,
            'balanceDisplay' => mh_grid_dashboard_format_money($money !== null ? (float)$money['amount'] : null, $money !== null ? trim((string)($money['currency'] ?? $currency)) : $currency),
            'updatedAt' => trim((string)($row['updated_at_utc'] ?? $row['created_at_utc'] ?? '')),
        ];
    }

    return $accounts;
}

function mh_grid_dashboard_active_session(PDO $db, string $tenantId): ?array
{
    $stmt = $db->prepare("
        SELECT sr_auth_credential_id, sr_auth_session_id, session_status, expires_at_utc, raw_snapshot_json
        FROM mh_settlement_auth_sessions
        WHERE tenant_id = ?
        ORDER BY COALESCE(expires_at_utc, '9999-12-31 23:59:59') DESC, updated_at_utc DESC, created_at_utc DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $snapshot = mh_grid_dashboard_json_decode($row['raw_snapshot_json'] ?? '');
    return [
        'credentialId' => trim((string)($row['sr_auth_credential_id'] ?? '')),
        'sessionId' => trim((string)($row['sr_auth_session_id'] ?? '')),
        'status' => trim((string)($row['session_status'] ?? 'unknown')),
        'expiresAt' => trim((string)($snapshot['expiresAt'] ?? $row['expires_at_utc'] ?? '')),
    ];
}

function mh_grid_dashboard_recent_quotes(PDO $db, string $tenantId, int $limit = 8, int $offset = 0): array
{
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $stmt = $db->prepare("
        SELECT sr_quote_id, sr_internal_account_id, quote_status, source_type, destination_type, transaction_id, raw_request_json, created_at_utc, updated_at_utc, executed_at_utc
        FROM mh_settlement_quotes
        WHERE tenant_id = ?
        ORDER BY COALESCE(updated_at_utc, created_at_utc) DESC, id DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute([$tenantId]);

    $quotes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $request = mh_grid_dashboard_json_decode($row['raw_request_json'] ?? '');
        $amount = isset($request['lockedCurrencyAmount']) && is_numeric($request['lockedCurrencyAmount'])
            ? (float)$request['lockedCurrencyAmount']
            : null;
        $currency = '';
        $destination = isset($request['destination']) && is_array($request['destination']) ? $request['destination'] : [];
        if (isset($destination['currency']) && is_string($destination['currency'])) {
            $currency = trim((string)$destination['currency']);
        }
        $quotes[] = [
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
        ];
    }

    return $quotes;
}

function mh_grid_dashboard_token_summary(PDO $pdoTok, string $username, int $ledgerLimit = 10): array
{
    mh_tokenomics_ensure_schema($pdoTok);
    $utilityId = function_exists('mh_tokenomics_seed_utility_token') ? mh_tokenomics_seed_utility_token($pdoTok) : 0;
    $cultureIds = function_exists('mh_tokenomics_seed_culture_coins') ? mh_tokenomics_seed_culture_coins($pdoTok) : [];
    $ledgerLimit = max(1, min(500, $ledgerLimit));

    $utilityTokens = 0;
    $utilityPriceUsd = null;
    $utilityTicker = 'MTK';
    $utilityMeta = [
        'minBuyUsd' => 49,
        'bonusStartUsd' => 100,
        'bonusBasePct' => 5.0,
        'bonusStepUsd' => 50,
        'bonusStepPct' => 1.0,
        'maxBonusPct' => 20.0,
    ];
    if ($utilityId > 0) {
        $balance = mh_tokenomics_get_balance($pdoTok, $username, $utilityId);
        $utilityTokens = is_int($balance) ? $balance : 0;
        $utilityPriceUsd = mh_tokenomics_get_current_price_usd($pdoTok, $utilityId);
        $stmt = $pdoTok->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$utilityId]);
        $rawMeta = $stmt->fetchColumn();
        $decodedMeta = is_string($rawMeta) ? json_decode($rawMeta, true) : [];
        if (is_array($decodedMeta)) {
            $utilityTicker = isset($decodedMeta['ticker']) && is_string($decodedMeta['ticker']) && trim($decodedMeta['ticker']) !== ''
                ? strtoupper(trim((string)$decodedMeta['ticker']))
                : $utilityTicker;
            if (isset($decodedMeta['bonus_schedule']) && is_array($decodedMeta['bonus_schedule'])) {
                $bonusSchedule = $decodedMeta['bonus_schedule'];
                if (isset($bonusSchedule['start_usd']) && is_numeric($bonusSchedule['start_usd'])) {
                    $utilityMeta['bonusStartUsd'] = max(1, (int)$bonusSchedule['start_usd']);
                }
                if (isset($bonusSchedule['base_bonus_pct']) && is_numeric($bonusSchedule['base_bonus_pct'])) {
                    $utilityMeta['bonusBasePct'] = max(0.0, min(100.0, (float)$bonusSchedule['base_bonus_pct']));
                }
                if (isset($bonusSchedule['step_usd']) && is_numeric($bonusSchedule['step_usd'])) {
                    $utilityMeta['bonusStepUsd'] = max(1, (int)$bonusSchedule['step_usd']);
                }
                if (isset($bonusSchedule['step_bonus_pct']) && is_numeric($bonusSchedule['step_bonus_pct'])) {
                    $utilityMeta['bonusStepPct'] = max(0.0, min(100.0, (float)$bonusSchedule['step_bonus_pct']));
                }
                if (isset($bonusSchedule['max_bonus_pct']) && is_numeric($bonusSchedule['max_bonus_pct'])) {
                    $utilityMeta['maxBonusPct'] = max(0.0, min(100.0, (float)$bonusSchedule['max_bonus_pct']));
                }
            }
        }
    }

    $cultureBalances = [];
    $wealthCoins = 0;
    foreach ($cultureIds as $key => $assetClassId) {
        $balance = is_int($assetClassId) && $assetClassId > 0 ? mh_tokenomics_get_balance($pdoTok, $username, $assetClassId) : null;
        $units = is_int($balance) ? $balance : 0;
        $wealthCoins += $units;
        $displayName = (string)$key;
        $ticker = strtoupper((string)$key);
        $meta = [];
        if (is_int($assetClassId) && $assetClassId > 0) {
            $stmt = $pdoTok->prepare("SELECT asset_key, display_name, pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
            $stmt->execute([$assetClassId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $displayName = trim((string)($row['display_name'] ?? $displayName)) ?: $displayName;
                $meta = mh_grid_dashboard_json_decode($row['pricing_params_json'] ?? '');
                if (isset($meta['ticker']) && is_string($meta['ticker']) && trim($meta['ticker']) !== '') {
                    $ticker = strtoupper(trim((string)$meta['ticker']));
                }
            }
        }
        $currentPriceUsd = is_int($assetClassId) && $assetClassId > 0 ? mh_tokenomics_get_current_price_usd($pdoTok, $assetClassId) : null;
        $cultureBalances[] = [
            'key' => (string)$key,
            'assetClassId' => (int)$assetClassId,
            'displayName' => $displayName,
            'ticker' => $ticker,
            'balance' => $units,
            'currentPriceUsd' => $currentPriceUsd,
            'estimatedValueUsd' => $currentPriceUsd !== null ? ((float)$units * (float)$currentPriceUsd) : null,
            'supplyCap' => isset($meta['supply_cap']) && is_numeric($meta['supply_cap'])
                ? (int)$meta['supply_cap']
                : (isset($meta['cap']) && is_numeric($meta['cap']) ? (int)$meta['cap'] : null),
        ];
    }

    $stmt = $pdoTok->prepare("
        SELECT COUNT(*) FROM mh_token_transfer_requests
        WHERE (payer_username = ? OR requester_username = ?)
          AND status = 'pending'
    ");
    $stmt->execute([$username, $username]);
    $pendingRequests = (int)$stmt->fetchColumn();

    $stmt = $pdoTok->prepare("
        SELECT COUNT(*) FROM mh_token_transfer_intents
        WHERE (sender_username = ? OR receiver_username = ?)
          AND status = 'pending'
    ");
    $stmt->execute([$username, $username]);
    $pendingTransfers = (int)$stmt->fetchColumn();

    $tenantId = mh_tokenomics_tenant_id($username);
    $stmt = $pdoTok->prepare("
        SELECT t.created_at, t.direction, t.units, t.service_key, t.reference_id, c.display_name, c.asset_key
        FROM mh_asset_transactions t
        LEFT JOIN mh_asset_classes c ON c.id = t.asset_class_id
        WHERE t.tenant_id = ? AND t.username = ?
        ORDER BY t.id DESC
        LIMIT {$ledgerLimit}
    ");
    $stmt->execute([$tenantId, $username]);
    $ledger = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $ledger[] = [
            'createdAt' => trim((string)($row['created_at'] ?? '')),
            'direction' => trim((string)($row['direction'] ?? '')),
            'units' => (int)($row['units'] ?? 0),
            'serviceKey' => trim((string)($row['service_key'] ?? '')),
            'referenceId' => trim((string)($row['reference_id'] ?? '')),
            'assetKey' => trim((string)($row['asset_key'] ?? '')),
            'assetName' => trim((string)($row['display_name'] ?? '')),
        ];
    }

    return [
        'utilityTokens' => $utilityTokens,
        'utilityTicker' => $utilityTicker,
        'utilityPriceUsd' => $utilityPriceUsd,
        'utilityMeta' => $utilityMeta,
        'wealthCoins' => $wealthCoins,
        'cultureBalances' => $cultureBalances,
        'pendingRequests' => $pendingRequests,
        'pendingTransfers' => $pendingTransfers,
        'recentLedger' => $ledger,
    ];
}

function mh_grid_dashboard_equity_summary(string $username): array
{
    $equityUnits = 0;
    $shareHolding = 0.0;
    $estimatedValueUsd = 0.0;
    $positions = [];

    try {
        $pdoEquity = getEquityConnection();
        $stmt = $pdoEquity->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM equity_ledger WHERE username = ?");
        $stmt->execute([$username]);
        $equityUnits = (int)$stmt->fetchColumn();

        $stmt = $pdoEquity->prepare("
            SELECT COALESCE(SUM(l.units_owned / c.fractional_units_per_share), 0)
            FROM equity_ledger l
            JOIN equity_classes c ON l.class_id = c.id
            WHERE l.username = ?
        ");
        $stmt->execute([$username]);
        $shareHolding = (float)$stmt->fetchColumn();

        $stmt = $pdoEquity->prepare("
            SELECT l.class_id, l.units_owned, c.name, c.fractional_units_per_share
            FROM equity_ledger l
            JOIN equity_classes c ON c.id = l.class_id
            WHERE l.username = ? AND l.units_owned > 0
            ORDER BY l.units_owned DESC, l.class_id ASC
            LIMIT 8
        ");
        $stmt->execute([$username]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $classId = (int)($row['class_id'] ?? 0);
            $unitsOwned = (int)($row['units_owned'] ?? 0);
            $unitsPerShare = max(1, (int)($row['fractional_units_per_share'] ?? 1));
            $shares = $unitsOwned / $unitsPerShare;
            $pricePerShare = $classId > 0 ? mh_equity_get_price_per_share($pdoEquity, $classId) : 0.0;
            $positionValueUsd = $shares * max(0.0, $pricePerShare);
            $estimatedValueUsd += $positionValueUsd;
            $positions[] = [
                'classId' => $classId,
                'className' => trim((string)($row['name'] ?? 'Equity class')) ?: 'Equity class',
                'unitsOwned' => $unitsOwned,
                'shares' => $shares,
                'pricePerShareUsd' => $pricePerShare,
                'estimatedValueUsd' => $positionValueUsd,
            ];
        }
    } catch (Throwable) {
        $equityUnits = 0;
        $shareHolding = 0.0;
        $estimatedValueUsd = 0.0;
        $positions = [];
    }

    return [
        'equityCoins' => $equityUnits,
        'shareHolding' => $shareHolding,
        'estimatedValueUsd' => $estimatedValueUsd,
        'positions' => $positions,
    ];
}

function mh_grid_dashboard_build_activity(array $quotes, array $ledger, ?int $limit = 12): array
{
    $activity = [];
    foreach ($quotes as $quote) {
        $when = trim((string)($quote['updatedAt'] ?: $quote['createdAt']));
        $activity[] = [
            'kind' => 'settlement',
            'title' => 'Grid quote ' . ($quote['status'] !== '' ? strtolower($quote['status']) : 'updated'),
            'detail' => trim((string)($quote['sourceType'] ?? '')) . ' -> ' . trim((string)($quote['destinationType'] ?? '')),
            'amountDisplay' => trim((string)($quote['amountDisplay'] ?? 'Unavailable')),
            'status' => trim((string)($quote['status'] ?? 'unknown')),
            'happenedAt' => $when,
            'link' => '/hub/grid/payments.php',
        ];
    }

    foreach ($ledger as $entry) {
        $assetName = trim((string)($entry['assetName'] ?? 'Asset'));
        $units = (int)($entry['units'] ?? 0);
        $direction = trim((string)($entry['direction'] ?? 'meta'));
        $serviceKey = trim((string)($entry['serviceKey'] ?? 'activity'));
        $activity[] = [
            'kind' => 'platform',
            'title' => $assetName !== '' ? $assetName : 'Platform asset activity',
            'detail' => $serviceKey !== '' ? $serviceKey : 'Ledger event',
            'amountDisplay' => ($direction !== '' ? strtoupper($direction) . ' ' : '') . number_format($units),
            'status' => 'posted',
            'happenedAt' => trim((string)($entry['createdAt'] ?? '')),
            'link' => '/hub/tokens/tokens.php',
        ];
    }

    usort($activity, static function (array $a, array $b): int {
        return strcmp((string)($b['happenedAt'] ?? ''), (string)($a['happenedAt'] ?? ''));
    });

    if ($limit === null) {
        return $activity;
    }

    return array_slice($activity, 0, max(1, $limit));
}

function mh_grid_dashboard_capabilities(array $platform, bool $hasAccount, bool $hasSession): array
{
    $environment = trim((string)($platform['environment'] ?? 'unknown'));
    $production = $environment === 'production';
    return [
        [
            'key' => 'transfers',
            'label' => 'Transfer Center',
            'state' => $hasAccount ? ($hasSession ? 'live' : 'action-required') : 'blocked',
            'reason' => $hasAccount
                ? ($hasSession ? 'Quote create/execute flow is available through the current Grid session.' : 'Authorize a Grid session to sign outgoing wallet actions.')
                : 'Create or discover the tenant embedded wallet account first.',
            'href' => '/hub/grid/payments.php',
        ],
        [
            'key' => 'add-funds',
            'label' => 'Add Funds',
            'state' => $hasAccount ? 'live' : 'blocked',
            'reason' => $hasAccount ? 'Stablecoin funding and withdrawal use the settlement rail account once configured.' : 'No embedded wallet account discovered yet.',
            'href' => '/hub/grid/payments.php',
        ],
        [
            'key' => 'cards',
            'label' => 'Cards',
            'state' => $production ? 'gated' : 'sandbox-only',
            'reason' => $production ? 'Requires Lightspark card program enablement for this platform.' : 'Cards stay hidden until the platform is production-enabled.',
            'href' => '',
        ],
        [
            'key' => 'rewards',
            'label' => 'Rewards',
            'state' => $production ? 'gated' : 'sandbox-only',
            'reason' => $production ? 'Requires platform rewards enablement.' : 'Rewards are not meaningful on the current sandbox platform.',
            'href' => '',
        ],
        [
            'key' => 'ramps',
            'label' => 'Ramps',
            'state' => $production ? 'gated' : 'sandbox-only',
            'reason' => $production ? 'Requires production ramp configuration and compliance review.' : 'Real ramp partners are not active in sandbox.',
            'href' => '',
        ],
        [
            'key' => 'global-p2p',
            'label' => 'Global P2P',
            'state' => $production ? 'gated' : 'sandbox-only',
            'reason' => $production ? 'Requires corridor and product enablement before exposure in the dashboard.' : 'Global P2P remains gated until production.',
            'href' => '',
        ],
    ];
}

function mh_grid_dashboard_status_payload(): array
{
    $username = mh_grid_dashboard_current_user();
    $tenantId = mh_grid_dashboard_current_tenant_id();
    if ($username === '' || $tenantId === '') {
        throw new RuntimeException('auth_required');
    }

    $db = mh_grid_dashboard_db();
    $accounts = mh_grid_dashboard_settlement_accounts($db, $tenantId);
    if ($accounts === []) {
        mh_grid_discover_embedded_wallet_accounts_for_tenant($tenantId);
        $accounts = mh_grid_dashboard_settlement_accounts($db, $tenantId);
    }

    $cfg = mh_grid_read_cfg();
    $platform = mh_grid_dashboard_platform_summary($cfg);
    $activeSession = mh_grid_dashboard_active_session($db, $tenantId);
    $recentQuotes = mh_grid_dashboard_recent_quotes($db, $tenantId);

    $tokenSummary = [
        'utilityTokens' => 0,
        'wealthCoins' => 0,
        'cultureBalances' => [],
        'pendingRequests' => 0,
        'pendingTransfers' => 0,
        'recentLedger' => [],
    ];
    try {
        $pdoTok = mh_tokenomics_get_tokenomics_pdo();
        if ($pdoTok instanceof PDO) {
            $tokenSummary = mh_grid_dashboard_token_summary($pdoTok, $username);
        }
    } catch (Throwable) {
        // Keep the dashboard available even if tokenomics is temporarily unavailable.
    }

    $equity = mh_grid_dashboard_equity_summary($username);
    $primaryAccountId = $accounts !== [] ? (string)$accounts[0]['accountId'] : '';
    $stablecoinAmount = null;
    $stablecoinCurrency = '';
    foreach ($accounts as $account) {
        if (isset($account['balanceAmount']) && is_numeric($account['balanceAmount'])) {
            $stablecoinAmount = ($stablecoinAmount ?? 0.0) + (float)$account['balanceAmount'];
            $stablecoinCurrency = trim((string)($account['balanceCurrency'] ?? $stablecoinCurrency));
        }
    }

    return [
        'ok' => true,
        'tenantId' => $tenantId,
        'username' => $username,
        'role' => trim((string)($_SESSION['mh_auth_role'] ?? 'Users')),
        'isAdmin' => mh_grid_dashboard_is_admin(),
        'platform' => $platform,
        'grid' => [
            'primaryAccountId' => $primaryAccountId,
            'activeSession' => $activeSession,
            'accounts' => $accounts,
            'recentQuotes' => $recentQuotes,
            'authCredentialCount' => (int)$db->query("SELECT COUNT(*) FROM mh_settlement_auth_credentials WHERE tenant_id = " . $db->quote($tenantId))->fetchColumn(),
            'capabilities' => mh_grid_dashboard_capabilities($platform, $primaryAccountId !== '', $activeSession !== null && trim((string)($activeSession['status'] ?? '')) !== 'expired'),
        ],
        'wallet' => [
            'equityCoins' => (int)$equity['equityCoins'],
            'shareHolding' => (float)$equity['shareHolding'],
            'equityEstimatedValueUsd' => (float)($equity['estimatedValueUsd'] ?? 0.0),
            'utilityTokens' => (int)$tokenSummary['utilityTokens'],
            'wealthCoins' => (int)$tokenSummary['wealthCoins'],
            'stablecoinAmount' => $stablecoinAmount,
            'stablecoinCurrency' => $stablecoinCurrency !== '' ? $stablecoinCurrency : 'USD',
            'stablecoinDisplay' => mh_grid_dashboard_format_money($stablecoinAmount, $stablecoinCurrency !== '' ? $stablecoinCurrency : 'USD'),
            'settlementAccountsCount' => count($accounts),
        ],
        'tokenFlow' => [
            'utilityTicker' => (string)($tokenSummary['utilityTicker'] ?? 'MTK'),
            'utilityPriceUsd' => $tokenSummary['utilityPriceUsd'],
            'utilityMeta' => $tokenSummary['utilityMeta'],
            'pendingRequests' => (int)$tokenSummary['pendingRequests'],
            'pendingTransfers' => (int)$tokenSummary['pendingTransfers'],
            'cultureBalances' => $tokenSummary['cultureBalances'],
        ],
        'equity' => [
            'positions' => $equity['positions'] ?? [],
            'estimatedValueUsd' => (float)($equity['estimatedValueUsd'] ?? 0.0),
        ],
        'activity' => mh_grid_dashboard_build_activity($recentQuotes, $tokenSummary['recentLedger']),
        'links' => [
            'wallet' => '/hub/wallet.php',
            'payments' => '/hub/grid/payments.php',
            'passkey' => '/hub/grid/passkey.php',
            'transactions' => '/hub/grid/transactions.php',
            'tokens' => '/hub/tokens/tokens.php',
            'tokenization' => '/hub/genesis/tokenization.php',
            'culture' => '/hub/coins/culture.php',
            'equity' => '/hub/equity/manage.php',
            'adminTokenomics' => '/control/tokenomics-management.php',
        ],
    ];
}

if (!defined('MH_GRID_DASHBOARD_LIB_ONLY')) {
    if (mh_grid_dashboard_current_user() === '') {
        mh_grid_dashboard_json(401, ['ok' => false, 'error' => 'auth_required']);
    }

    try {
        mh_grid_dashboard_json(200, mh_grid_dashboard_status_payload());
    } catch (Throwable $e) {
        $status = $e->getMessage() === 'auth_required' ? 401 : 500;
        mh_grid_dashboard_json($status, [
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
    }
}
