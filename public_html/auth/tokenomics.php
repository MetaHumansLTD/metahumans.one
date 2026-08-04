<?php
declare(strict_types=1);

if (!function_exists('mh_tokenomics_tenant_id')) {
    function mh_tokenomics_tenant_id(string $username): string {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $t = $_SESSION['mh_tenant_id'] ?? null;
            if (is_string($t) && trim($t) !== '') {
                return trim($t);
            }
        }
        return 'user:' . $username;
    }
}

if (!function_exists('mh_tokenomics_persona_id')) {
    function mh_tokenomics_persona_id(): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) return null;
        foreach (['mh_selected_persona', 'mh_auth_persona'] as $k) {
            $v = $_SESSION[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }
        return null;
    }
}

if (!function_exists('mh_tokenomics_meta_human_id')) {
    function mh_tokenomics_meta_human_id(): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) return null;
        $v = $_SESSION['mh_meta_human_id'] ?? null;
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        return null;
    }
}

if (!function_exists('mh_tokenomics_ensure_schema')) {
    function mh_tokenomics_ensure_schema(PDO $pdo): void {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_asset_classes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_key VARCHAR(128) NOT NULL,
            asset_type VARCHAR(32) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            decimals INT NOT NULL DEFAULT 0,
            pricing_strategy VARCHAR(64) NOT NULL DEFAULT 'fixed',
            pricing_params_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_asset_key (asset_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_asset_pricing_rules (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_class_id BIGINT NOT NULL,
            price_usd_per_unit DECIMAL(18,8) NOT NULL DEFAULT 0.00000000,
            pricing_strategy VARCHAR(64) NOT NULL DEFAULT 'fixed',
            pricing_params_json LONGTEXT NULL,
            effective_from DATETIME NOT NULL,
            effective_to DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_mh_asset_pricing_class (asset_class_id),
            KEY idx_mh_asset_pricing_effective (effective_from, effective_to),
            CONSTRAINT fk_mh_asset_pricing_class FOREIGN KEY (asset_class_id) REFERENCES mh_asset_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_asset_ledger (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id VARCHAR(255) NOT NULL,
            username VARCHAR(255) NOT NULL,
            asset_class_id BIGINT NOT NULL,
            units_owned BIGINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_asset_ledger (tenant_id, username, asset_class_id),
            KEY idx_mh_asset_ledger_user (username),
            KEY idx_mh_asset_ledger_tenant (tenant_id),
            CONSTRAINT fk_mh_asset_ledger_class FOREIGN KEY (asset_class_id) REFERENCES mh_asset_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { $pdo->exec("ALTER TABLE mh_asset_ledger ADD KEY idx_mh_asset_ledger_asset (asset_class_id)"); } catch (Throwable) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_asset_transactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            prev_hash VARCHAR(64) NOT NULL,
            txn_hash VARCHAR(64) NOT NULL,
            tenant_id VARCHAR(255) NOT NULL,
            username VARCHAR(255) NOT NULL,
            persona_id VARCHAR(255) NULL,
            meta_human_id VARCHAR(255) NULL,
            asset_class_id BIGINT NOT NULL,
            direction VARCHAR(16) NOT NULL,
            units BIGINT NOT NULL,
            service_key VARCHAR(255) NULL,
            reference_id VARCHAR(255) NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_asset_txn_hash (txn_hash),
            KEY idx_mh_asset_txn_tenant_user (tenant_id, username, created_at),
            KEY idx_mh_asset_txn_service (service_key),
            KEY idx_mh_asset_txn_ref (reference_id),
            CONSTRAINT fk_mh_asset_txn_class FOREIGN KEY (asset_class_id) REFERENCES mh_asset_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS mh_service_pricing (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            service_key VARCHAR(255) NOT NULL,
            tokens_per_unit INT NOT NULL DEFAULT 1,
            unit_name VARCHAR(64) NOT NULL DEFAULT 'unit',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            effective_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            effective_to DATETIME NULL,
            updated_by VARCHAR(255) NULL,
            meta_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mh_service_key (service_key),
            KEY idx_mh_service_effective (effective_from, effective_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('mh_tokenomics_get_tokenomics_pdo')) {
    function mh_tokenomics_resolve_database_config_id(): string {
        $preferred = [
            'db_equity_dedicated',
            'tenant:equity',
            'Equity (Dedicated)',
        ];

        if (function_exists('database_getConfiguration')) {
            foreach ($preferred as $candidate) {
                $cfg = database_getConfiguration($candidate);
                if (is_array($cfg)) {
                    $resolvedId = trim((string)($cfg['id'] ?? ''));
                    if ($resolvedId !== '') {
                        return $resolvedId;
                    }
                    if ($candidate !== '') {
                        return $candidate;
                    }
                }
            }
        }

        if (function_exists('database_loadConfigurations')) {
            $configs = database_loadConfigurations();
            foreach ($configs as $configId => $cfg) {
                if (!is_array($cfg)) {
                    continue;
                }
                $id = trim((string)($cfg['id'] ?? $configId));
                $name = trim((string)($cfg['name'] ?? ''));
                $context = strtolower(trim((string)($cfg['context'] ?? '')));
                if ($context === 'equity' || strcasecmp($name, 'tenant:equity') === 0 || strcasecmp($name, 'Equity (Dedicated)') === 0) {
                    return $id !== '' ? $id : (string)$configId;
                }
            }
        }

        throw new RuntimeException('tokenomics_database_configuration_not_found');
    }

    function mh_tokenomics_get_tokenomics_pdo(): PDO {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getConnectionById')) {
            throw new RuntimeException('database_module_unavailable');
        }
        $resolvedConfigId = mh_tokenomics_resolve_database_config_id();
        $pdo = database_getConnectionById($resolvedConfigId);
        if (!$pdo instanceof PDO) {
            if (is_object($pdo) && property_exists($pdo, 'pdo') && $pdo->pdo instanceof PDO) {
                $pdo = $pdo->pdo;
            } elseif (is_array($pdo) && isset($pdo['pdo']) && $pdo['pdo'] instanceof PDO) {
                $pdo = $pdo['pdo'];
            }
        }
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('tokenomics_connection_failed');
        }
        return $pdo;
    }
}

if (!function_exists('mh_tokenomics_get_asset_class_id')) {
    function mh_tokenomics_get_asset_class_id(PDO $pdo, string $assetKey, array $defaults = []): int {
        mh_tokenomics_ensure_schema($pdo);
        $assetKey = trim($assetKey);
        if ($assetKey === '') return 0;
        $stmt = $pdo->prepare("SELECT id FROM mh_asset_classes WHERE asset_key = ? LIMIT 1");
        $stmt->execute([$assetKey]);
        $id = (int)$stmt->fetchColumn();
        if ($id > 0) return $id;
        $assetType = isset($defaults['asset_type']) && is_string($defaults['asset_type']) ? trim((string)$defaults['asset_type']) : 'utility';
        $displayName = isset($defaults['display_name']) && is_string($defaults['display_name']) ? trim((string)$defaults['display_name']) : $assetKey;
        $decimals = isset($defaults['decimals']) ? (int)$defaults['decimals'] : 0;
        $strategy = isset($defaults['pricing_strategy']) && is_string($defaults['pricing_strategy']) ? trim((string)$defaults['pricing_strategy']) : 'fixed';
        $params = isset($defaults['pricing_params_json']) && is_string($defaults['pricing_params_json']) ? trim((string)$defaults['pricing_params_json']) : null;
        $ins = $pdo->prepare("INSERT INTO mh_asset_classes (asset_key, asset_type, display_name, decimals, pricing_strategy, pricing_params_json) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->execute([$assetKey, $assetType, $displayName, $decimals, $strategy !== '' ? $strategy : 'fixed', $params !== '' ? $params : null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('mh_tokenomics_seed_utility_token')) {
    function mh_tokenomics_seed_utility_token(PDO $pdo): int {
        $classId = mh_tokenomics_get_asset_class_id($pdo, 'utility:meta', [
            'asset_type' => 'utility',
            'display_name' => 'Utility Token',
            'decimals' => 0,
            'pricing_strategy' => 'fixed',
        ]);
        if ($classId < 1) return 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM mh_asset_pricing_rules WHERE asset_class_id = ?");
        $stmt->execute([$classId]);
        $count = (int)$stmt->fetchColumn();
        if ($count < 1) {
            $ins = $pdo->prepare("INSERT INTO mh_asset_pricing_rules (asset_class_id, price_usd_per_unit, pricing_strategy, pricing_params_json, effective_from, effective_to) VALUES (?, ?, 'fixed', NULL, ?, NULL)");
            $ins->execute([$classId, 0.03, date('Y-m-d H:i:s')]);
        }
        return $classId;
    }
}

if (!function_exists('mh_tokenomics_bootstrap_user_utility_balance')) {
    function mh_tokenomics_bootstrap_user_utility_balance(PDO $pdo, string $tenantId, string $username): void {
        $utilityClassId = mh_tokenomics_seed_utility_token($pdo);
        if ($utilityClassId < 1) return;
        $stmt = $pdo->prepare("SELECT units_owned FROM mh_asset_ledger WHERE tenant_id = ? AND username = ? AND asset_class_id = ? LIMIT 1");
        $stmt->execute([$tenantId, $username, $utilityClassId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && is_array($row)) return;
        $tokens = 0;
        try {
            if (function_exists('cue_autoload')) {
                cue_autoload('database');
            }
            if (function_exists('database_getConnectionById')) {
                $pdoBio = database_getConnectionById('biometrics');
                if ($pdoBio instanceof PDO) {
                    $pdoBio->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $s = $pdoBio->prepare("SELECT tokens FROM users WHERE username = ? LIMIT 1");
                    $s->execute([$username]);
                    $v = $s->fetchColumn();
                    if ($v !== false) {
                        $tokens = max(0, (int)$v);
                    }
                }
            }
        } catch (Throwable) {
            $tokens = 0;
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, ?)");
        $ins->execute([$tenantId, $username, $utilityClassId, $tokens]);
    }
}

if (!function_exists('mh_tokenomics_get_utility_balance')) {
    function mh_tokenomics_get_utility_balance(PDO $pdo, string $username): ?int {
        mh_tokenomics_ensure_schema($pdo);
        $tenantId = mh_tokenomics_tenant_id($username);
        mh_tokenomics_bootstrap_user_utility_balance($pdo, $tenantId, $username);
        $utilityClassId = mh_tokenomics_seed_utility_token($pdo);
        if ($utilityClassId < 1) return null;
        $stmt = $pdo->prepare("SELECT units_owned FROM mh_asset_ledger WHERE tenant_id = ? AND username = ? AND asset_class_id = ? LIMIT 1");
        $stmt->execute([$tenantId, $username, $utilityClassId]);
        $bal = $stmt->fetchColumn();
        return $bal === false ? null : (int)$bal;
    }
}

if (!function_exists('mh_tokenomics_transfer_utility_tokens_exact')) {
    function mh_tokenomics_transfer_utility_tokens_exact(PDO $pdo, string $fromUser, string $toUser, int $amount, ?string $serviceKey = null, ?string $referenceId = null, mixed $meta = null): bool {
        $fromUser = trim($fromUser);
        $toUser = trim($toUser);
        $amount = (int)$amount;
        if ($fromUser === '' || $toUser === '' || $amount <= 0) return false;
        if ($fromUser === $toUser) return true;

        mh_tokenomics_ensure_schema($pdo);
        $utilityClassId = mh_tokenomics_seed_utility_token($pdo);
        if ($utilityClassId < 1) return false;

        $fromTenantId = mh_tokenomics_tenant_id($fromUser);
        $toTenantId = mh_tokenomics_tenant_id($toUser);
        mh_tokenomics_bootstrap_user_utility_balance($pdo, $fromTenantId, $fromUser);
        mh_tokenomics_bootstrap_user_utility_balance($pdo, $toTenantId, $toUser);

        $now = date('Y-m-d H:i:s');
        $metaJson = null;
        if (is_array($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
        } elseif (is_string($meta) && trim($meta) !== '') {
            $metaJson = json_encode(['note' => $meta], JSON_UNESCAPED_SLASHES);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT IGNORE INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, 0)")
                ->execute([$fromTenantId, $fromUser, $utilityClassId]);
            $pdo->prepare("INSERT IGNORE INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, 0)")
                ->execute([$toTenantId, $toUser, $utilityClassId]);

            $sel = $pdo->prepare("SELECT units_owned FROM mh_asset_ledger WHERE tenant_id = ? AND username = ? AND asset_class_id = ? FOR UPDATE");
            $sel->execute([$fromTenantId, $fromUser, $utilityClassId]);
            $fromBal = (int)($sel->fetchColumn() ?: 0);
            if ($fromBal < $amount) {
                $pdo->rollBack();
                return false;
            }
            $sel->execute([$toTenantId, $toUser, $utilityClassId]);
            $sel->fetchColumn();

            $pdo->prepare("UPDATE mh_asset_ledger SET units_owned = units_owned - ? WHERE tenant_id = ? AND username = ? AND asset_class_id = ?")
                ->execute([$amount, $fromTenantId, $fromUser, $utilityClassId]);
            $pdo->prepare("UPDATE mh_asset_ledger SET units_owned = units_owned + ? WHERE tenant_id = ? AND username = ? AND asset_class_id = ?")
                ->execute([$amount, $toTenantId, $toUser, $utilityClassId]);

            $last = $pdo->prepare("SELECT txn_hash FROM mh_asset_transactions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");

            $last->execute([$fromTenantId]);
            $prevHash = (string)($last->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000');
            $fromPayload = $prevHash . '|' . $fromTenantId . '|' . $fromUser . '|' . $utilityClassId . '|debit|' . $amount . '|' . ($serviceKey ?? '') . '|' . $now;
            $fromTxnHash = hash('sha256', $fromPayload . '|from');

            $last->execute([$toTenantId]);
            $prevHashTo = (string)($last->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000');
            $toPayload = $prevHashTo . '|' . $toTenantId . '|' . $toUser . '|' . $utilityClassId . '|credit|' . $amount . '|' . ($serviceKey ?? '') . '|' . $now;
            $toTxnHash = hash('sha256', $toPayload . '|to');

            $insTxn = $pdo->prepare("INSERT INTO mh_asset_transactions (prev_hash, txn_hash, tenant_id, username, persona_id, meta_human_id, asset_class_id, direction, units, service_key, reference_id, meta_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insTxn->execute([$prevHash, $fromTxnHash, $fromTenantId, $fromUser, null, null, $utilityClassId, 'debit', $amount, $serviceKey, $referenceId, $metaJson, $now]);
            $insTxn->execute([$prevHashTo, $toTxnHash, $toTenantId, $toUser, null, null, $utilityClassId, 'credit', $amount, $serviceKey, $referenceId, $metaJson, $now]);

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Tokenomics Transfer Error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mh_tokenomics_get_service_pricing')) {
    function mh_tokenomics_get_service_pricing(PDO $pdo, string $serviceKey, int $defaultTokensPerUnit = 1): array {
        mh_tokenomics_ensure_schema($pdo);
        $serviceKey = trim($serviceKey);
        if ($serviceKey === '') {
            return ['service_key' => '', 'tokens_per_unit' => max(1, $defaultTokensPerUnit), 'unit_name' => 'unit', 'enabled' => 1];
        }
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("
            SELECT *
            FROM mh_service_pricing
            WHERE service_key = ?
              AND enabled = 1
              AND effective_from <= ?
              AND (effective_to IS NULL OR effective_to > ?)
            LIMIT 1
        ");
        $stmt->execute([$serviceKey, $now, $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row)) return $row;
        $ins = $pdo->prepare("INSERT INTO mh_service_pricing (service_key, tokens_per_unit, unit_name, enabled, effective_from, effective_to) VALUES (?, ?, 'unit', 1, ?, NULL)");
        $ins->execute([$serviceKey, max(1, $defaultTokensPerUnit), $now]);
        return [
            'service_key' => $serviceKey,
            'tokens_per_unit' => max(1, $defaultTokensPerUnit),
            'unit_name' => 'unit',
            'enabled' => 1,
            'effective_from' => $now,
            'effective_to' => null,
        ];
    }
}

if (!function_exists('mh_tokenomics_apply_delta')) {
    function mh_tokenomics_apply_delta(PDO $pdo, string $username, int $assetClassId, int $deltaUnits, ?string $serviceKey, ?string $referenceId, mixed $meta): bool {
        if ($assetClassId < 1 || $deltaUnits === 0) return $deltaUnits === 0;
        mh_tokenomics_ensure_schema($pdo);
        $tenantId = mh_tokenomics_tenant_id($username);
        $personaId = mh_tokenomics_persona_id();
        $metaHumanId = mh_tokenomics_meta_human_id();
        $now = date('Y-m-d H:i:s');
        $metaJson = null;
        if (is_array($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES);
        } elseif (is_string($meta) && trim($meta) !== '') {
            $metaJson = json_encode(['note' => $meta], JSON_UNESCAPED_SLASHES);
        }
        $pdo->beginTransaction();
        try {
            $sel = $pdo->prepare("SELECT id, units_owned FROM mh_asset_ledger WHERE tenant_id = ? AND username = ? AND asset_class_id = ? FOR UPDATE");
            $sel->execute([$tenantId, $username, $assetClassId]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $ins = $pdo->prepare("INSERT INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, 0)");
                $ins->execute([$tenantId, $username, $assetClassId]);
                $sel->execute([$tenantId, $username, $assetClassId]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);
            }
            $owned = (int)($row['units_owned'] ?? 0);
            if ($deltaUnits < 0 && $owned < abs($deltaUnits)) {
                $pdo->rollBack();
                return false;
            }
            $upd = $pdo->prepare("UPDATE mh_asset_ledger SET units_owned = units_owned + ? WHERE tenant_id = ? AND username = ? AND asset_class_id = ?");
            $upd->execute([$deltaUnits, $tenantId, $username, $assetClassId]);
            $last = $pdo->prepare("SELECT txn_hash FROM mh_asset_transactions WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
            $last->execute([$tenantId]);
            $prevHash = (string)($last->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000');
            $payload = $prevHash . '|' . $tenantId . '|' . $username . '|' . $assetClassId . '|' . $deltaUnits . '|' . ($serviceKey ?? '') . '|' . $now;
            $txnHash = hash('sha256', $payload);
            $direction = $deltaUnits < 0 ? 'debit' : 'credit';
            $insTxn = $pdo->prepare("INSERT INTO mh_asset_transactions (prev_hash, txn_hash, tenant_id, username, persona_id, meta_human_id, asset_class_id, direction, units, service_key, reference_id, meta_json, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insTxn->execute([$prevHash, $txnHash, $tenantId, $username, $personaId, $metaHumanId, $assetClassId, $direction, abs($deltaUnits), $serviceKey, $referenceId, $metaJson, $now]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log("Tokenomics Apply Delta Error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mh_tokenomics_debit_utility_tokens_exact')) {
    function mh_tokenomics_debit_utility_tokens_exact(PDO $pdo, string $username, int $amount, ?string $serviceKey, mixed $meta): bool {
        $amount = (int)$amount;
        if ($amount <= 0) return true;
        $tenantId = mh_tokenomics_tenant_id($username);
        mh_tokenomics_bootstrap_user_utility_balance($pdo, $tenantId, $username);
        $utilityClassId = mh_tokenomics_seed_utility_token($pdo);
        if ($utilityClassId < 1) return false;
        $ok = mh_tokenomics_apply_delta($pdo, $username, $utilityClassId, -$amount, $serviceKey, null, $meta);
        if (!$ok) return false;
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] === $username) {
            $bal = mh_tokenomics_get_utility_balance($pdo, $username);
            if (is_int($bal)) {
                $_SESSION['tokens'] = $bal;
            }
        }
        return true;
    }
}

if (!function_exists('mh_charge_service_tokens')) {
    function mh_charge_service_tokens(string $username, string $serviceKey, int $units = 1, array $meta = [], int $defaultTokensPerUnit = 1): array {
        $pdo = mh_tokenomics_get_tokenomics_pdo();
        mh_tokenomics_ensure_schema($pdo);
        $tenantId = mh_tokenomics_tenant_id($username);
        mh_tokenomics_bootstrap_user_utility_balance($pdo, $tenantId, $username);
        $utilityClassId = mh_tokenomics_seed_utility_token($pdo);
        if ($utilityClassId < 1) {
            return ['success' => false, 'error' => 'tokenomics_not_ready'];
        }
        $units = max(1, (int)$units);
        $pricing = mh_tokenomics_get_service_pricing($pdo, $serviceKey, $defaultTokensPerUnit);
        $tpu = (int)($pricing['tokens_per_unit'] ?? $defaultTokensPerUnit);
        $tpu = max(1, $tpu);
        $total = $tpu * $units;
        $ok = mh_tokenomics_apply_delta($pdo, $username, $utilityClassId, -$total, $serviceKey, null, array_merge($meta, ['units' => $units, 'tokens_per_unit' => $tpu]));
        if (!$ok) {
            $bal = mh_tokenomics_get_utility_balance($pdo, $username);
            return ['success' => false, 'error' => 'insufficient_tokens', 'tokens' => is_int($bal) ? $bal : 0, 'debited' => 0];
        }
        $bal = mh_tokenomics_get_utility_balance($pdo, $username);
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['mh_auth_user']) && $_SESSION['mh_auth_user'] === $username && is_int($bal)) {
            $_SESSION['tokens'] = $bal;
        }
        return ['success' => true, 'debited' => $total, 'tokens' => is_int($bal) ? $bal : 0, 'tokens_per_unit' => $tpu, 'units' => $units];
    }
}

if (!function_exists('mh_tokenomics_get_balance')) {
    function mh_tokenomics_get_balance(PDO $pdo, string $username, int $assetClassId): ?int {
        $username = trim($username);
        if ($username === '' || $assetClassId < 1) return null;
        mh_tokenomics_ensure_schema($pdo);
        $tenantId = mh_tokenomics_tenant_id($username);
        $pdo->prepare("INSERT IGNORE INTO mh_asset_ledger (tenant_id, username, asset_class_id, units_owned) VALUES (?, ?, ?, 0)")
            ->execute([$tenantId, $username, $assetClassId]);
        $stmt = $pdo->prepare("SELECT units_owned FROM mh_asset_ledger WHERE tenant_id = ? AND username = ? AND asset_class_id = ? LIMIT 1");
        $stmt->execute([$tenantId, $username, $assetClassId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }
}

if (!function_exists('mh_tokenomics_get_balance_by_key')) {
    function mh_tokenomics_get_balance_by_key(PDO $pdo, string $username, string $assetKey): ?int {
        $assetKey = trim((string)$assetKey);
        if ($assetKey === '') return null;
        $assetClassId = mh_tokenomics_get_asset_class_id($pdo, $assetKey);
        if ($assetClassId < 1) return null;
        return mh_tokenomics_get_balance($pdo, $username, (int)$assetClassId);
    }
}

if (!function_exists('mh_tokenomics_get_current_price_usd')) {
    function mh_tokenomics_get_current_price_usd(PDO $pdo, int $assetClassId): ?float {
        if ($assetClassId < 1) return null;
        mh_tokenomics_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT price_usd_per_unit FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$assetClassId]);
        $v = $stmt->fetchColumn();
        if ($v === false) return null;
        $p = (float)$v;
        return $p > 0 ? $p : null;
    }
}

if (!function_exists('mh_tokenomics_seed_culture_coins')) {
    function mh_tokenomics_seed_culture_coins(PDO $pdo): array {
        mh_tokenomics_ensure_schema($pdo);
        $now = date('Y-m-d H:i:s');

        $champMeta = json_encode([
            'ticker' => 'mhc',
            'supply_cap' => 1000000,
            'spark' => 'spark.money',
            'name' => 'champcoin',
        ], JSON_UNESCAPED_SLASHES);
        $champId = mh_tokenomics_get_asset_class_id($pdo, 'culture:champcoin', [
            'asset_type' => 'culture',
            'display_name' => 'Champion Coin',
            'decimals' => 0,
            'pricing_strategy' => 'fixed',
            'pricing_params_json' => is_string($champMeta) ? $champMeta : null,
        ]);
        if ($champId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mh_asset_pricing_rules WHERE asset_class_id = ?");
            $stmt->execute([$champId]);
            $count = (int)$stmt->fetchColumn();
            if ($count < 1) {
                $ins = $pdo->prepare("INSERT INTO mh_asset_pricing_rules (asset_class_id, price_usd_per_unit, pricing_strategy, pricing_params_json, effective_from, effective_to) VALUES (?, ?, 'fixed', NULL, ?, NULL)");
                $ins->execute([$champId, 0.25, $now]);
            }
        }

        $superMeta = json_encode([
            'ticker' => 'mhs',
            'supply_cap' => 300000,
            'spark' => 'spark.money',
            'name' => 'supercoin',
        ], JSON_UNESCAPED_SLASHES);
        $superId = mh_tokenomics_get_asset_class_id($pdo, 'culture:supercoin', [
            'asset_type' => 'culture',
            'display_name' => 'Super Coin',
            'decimals' => 0,
            'pricing_strategy' => 'fixed',
            'pricing_params_json' => is_string($superMeta) ? $superMeta : null,
        ]);
        if ($superId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM mh_asset_pricing_rules WHERE asset_class_id = ?");
            $stmt->execute([$superId]);
            $count = (int)$stmt->fetchColumn();
            if ($count < 1) {
                $ins = $pdo->prepare("INSERT INTO mh_asset_pricing_rules (asset_class_id, price_usd_per_unit, pricing_strategy, pricing_params_json, effective_from, effective_to) VALUES (?, ?, 'fixed', NULL, ?, NULL)");
                $ins->execute([$superId, 1.00, '2026-07-01 00:00:00']);
            }
        }

        return [
            'champcoin' => (int)$champId,
            'supercoin' => (int)$superId,
        ];
    }
}
