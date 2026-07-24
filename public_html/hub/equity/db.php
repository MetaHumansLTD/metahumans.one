<?php

if (!function_exists('cue_autoload')) {
    require_once dirname(__DIR__, 2) . '/.cue/cue.php';
}

if (!function_exists('getEquityConnection')) {
    function mh_equity_coin_key(string $className): string {
        $n = strtolower(trim($className));
        if ($n === '') {
            return 'equity-coin';
        }
        if (strpos($n, 'preference') !== false || strpos($n, 'preferred') !== false) {
            return 'pref-equity-coin';
        }
        if (strpos($n, 'ordinary') !== false || strpos($n, 'common') !== false) {
            return 'ord-equity-coin';
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', $n);
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'equity';
        }
        return $slug . '-equity-coin';
    }

    function mh_equity_json_decode(mixed $value): array {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    function mh_equity_get_total_shares_issued(PDO $pdo, int $classId): float {
        $stmt = $pdo->prepare("SELECT fractional_units_per_share FROM equity_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$classId]);
        $unitsPerShare = (int)$stmt->fetchColumn();
        if ($unitsPerShare < 1) {
            $unitsPerShare = 1;
        }

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM equity_ledger WHERE class_id = ?");
        $stmt->execute([$classId]);
        $units = (float)$stmt->fetchColumn();
        return $units / $unitsPerShare;
    }

    function mh_equity_get_effective_pricing_rule(PDO $pdo, int $classId, ?string $at = null): ?array {
        $at = is_string($at) && trim($at) !== '' ? trim($at) : date('Y-m-d H:i:s');
        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM equity_pricing_rules
                WHERE class_id = ?
                  AND effective_from <= ?
                  AND (effective_to IS NULL OR effective_to > ?)
                ORDER BY effective_from DESC
                LIMIT 1
            ");
            $stmt->execute([$classId, $at, $at]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    function mh_equity_get_price_per_share(PDO $pdo, int $classId, ?string $at = null): float {
        $rule = mh_equity_get_effective_pricing_rule($pdo, $classId, $at);
        if (is_array($rule)) {
            $price = (float)($rule['price_per_share'] ?? 0);
            if ($price > 0) {
                return $price;
            }
        }

        $stmt = $pdo->prepare("SELECT price_per_share, pricing_strategy, pricing_params_json FROM equity_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$classId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !is_array($row)) {
            return 0.0;
        }

        $referencePrice = (float)($row['price_per_share'] ?? 0);
        $strategy = isset($row['pricing_strategy']) && is_string($row['pricing_strategy']) ? trim((string)$row['pricing_strategy']) : '';
        $params = mh_equity_json_decode($row['pricing_params_json'] ?? '');

        if ($strategy === '' || $strategy === 'fixed') {
            return max(0.0, $referencePrice);
        }

        if ($strategy === 'tiered') {
            $tiers = $params['tiers'] ?? null;
            if (!is_array($tiers) || empty($tiers)) {
                return max(0.0, $referencePrice);
            }
            $issued = mh_equity_get_total_shares_issued($pdo, $classId);
            $tiersNorm = [];
            foreach ($tiers as $t) {
                if (!is_array($t)) continue;
                $upTo = isset($t['up_to_shares']) ? (float)$t['up_to_shares'] : null;
                $p = (float)($t['price_per_share'] ?? 0);
                if ($p <= 0) continue;
                $tiersNorm[] = ['up_to_shares' => $upTo, 'price_per_share' => $p];
            }
            if (empty($tiersNorm)) {
                return max(0.0, $referencePrice);
            }
            usort($tiersNorm, function ($a, $b) {
                $au = $a['up_to_shares'];
                $bu = $b['up_to_shares'];
                if ($au === null && $bu === null) return 0;
                if ($au === null) return 1;
                if ($bu === null) return -1;
                return $au <=> $bu;
            });
            foreach ($tiersNorm as $t) {
                if ($t['up_to_shares'] === null) {
                    return (float)$t['price_per_share'];
                }
                if ($issued <= (float)$t['up_to_shares']) {
                    return (float)$t['price_per_share'];
                }
            }
            return (float)$tiersNorm[count($tiersNorm) - 1]['price_per_share'];
        }

        if ($strategy === 'bonding_curve_linear') {
            $base = (float)($params['base_price_per_share'] ?? $referencePrice);
            $slope = (float)($params['slope_per_share'] ?? 0);
            $issued = mh_equity_get_total_shares_issued($pdo, $classId);
            $price = $base + ($slope * max(0.0, $issued));
            return max(0.0, $price);
        }

        return max(0.0, $referencePrice);
    }

    function mh_equity_get_price_per_unit(PDO $pdo, int $classId, ?string $at = null): float {
        $stmt = $pdo->prepare("SELECT fractional_units_per_share FROM equity_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$classId]);
        $unitsPerShare = (int)$stmt->fetchColumn();
        if ($unitsPerShare < 1) $unitsPerShare = 1;
        $pps = mh_equity_get_price_per_share($pdo, $classId, $at);
        return $pps / $unitsPerShare;
    }

    function mh_equity_seed_default_classes(PDO $pdo): void {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM equity_classes")->fetchColumn();
        } catch (Throwable) {
            $count = 0;
        }

        if ($count > 0) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO equity_classes (name, description, total_shares, fractional_units_per_share, price_per_share, pricing_strategy, pricing_params_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute(['Ordinary Equity', 'Ordinary shares (common stock)', 0, 400, 15.00, 'fixed', null]);
            $stmt->execute(['Preference Equity', 'Preference shares (preferred stock)', 0, 400, 15.00, 'fixed', null]);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO equity_classes (name, description, total_shares, fractional_units_per_share, price_per_share, pricing_strategy, pricing_params_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute(['Ordinary Equity', 'Ordinary shares (common stock)', 0, 400, 15.00, 'fixed', null]);
        $stmt->execute(['Preference Equity', 'Preference shares (preferred stock)', 0, 400, 15.00, 'fixed', null]);
    }

    function mh_equity_ensure_schema(PDO $pdo): void {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_classes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT NULL,
            total_shares BIGINT NOT NULL DEFAULT 0,
            fractional_units_per_share BIGINT NOT NULL DEFAULT 400,
            price_per_share DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            pricing_strategy VARCHAR(64) NOT NULL DEFAULT 'fixed',
            pricing_params_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_equity_class_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_classes LIKE 'pricing_strategy'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_classes ADD COLUMN pricing_strategy VARCHAR(64) NOT NULL DEFAULT 'fixed' AFTER price_per_share");
            }
        } catch (Throwable) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_classes LIKE 'pricing_params_json'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_classes ADD COLUMN pricing_params_json LONGTEXT NULL AFTER pricing_strategy");
            }
        } catch (Throwable) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_ledger (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            class_id INT NOT NULL,
            units_owned BIGINT NOT NULL DEFAULT 0,
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_equity_ledger_user_class (username, class_id),
            KEY idx_equity_ledger_username (username),
            KEY idx_equity_ledger_class (class_id),
            CONSTRAINT fk_equity_ledger_class FOREIGN KEY (class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_transactions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            prev_hash VARCHAR(64) NOT NULL,
            txn_hash VARCHAR(64) NOT NULL,
            class_id INT NOT NULL,
            sender VARCHAR(255) NULL,
            recipient VARCHAR(255) NULL,
            units BIGINT NOT NULL DEFAULT 0,
            price_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            txn_type VARCHAR(32) NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_equity_txn_hash (txn_hash),
            KEY idx_equity_txn_class (class_id),
            KEY idx_equity_txn_sender (sender),
            KEY idx_equity_txn_recipient (recipient),
            CONSTRAINT fk_equity_txn_class FOREIGN KEY (class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_transactions LIKE 'price_per_unit'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_transactions ADD COLUMN price_per_unit DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER units");
            }
        } catch (Throwable) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_market (
            id INT AUTO_INCREMENT PRIMARY KEY,
            seller_username VARCHAR(255) NOT NULL,
            class_id INT NOT NULL,
            units_available BIGINT NOT NULL,
            price_per_unit DECIMAL(10,2) NOT NULL,
            listing_type VARCHAR(16) NOT NULL DEFAULT 'coin',
            display_qty BIGINT NULL,
            display_price DECIMAL(12,2) NULL,
            status ENUM('active', 'sold', 'cancelled') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_status (status),
            KEY idx_market_seller (seller_username),
            CONSTRAINT fk_equity_market_class FOREIGN KEY (class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_market LIKE 'listing_type'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_market ADD COLUMN listing_type VARCHAR(16) NOT NULL DEFAULT 'coin' AFTER price_per_unit");
            }
        } catch (Throwable) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_market LIKE 'display_qty'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_market ADD COLUMN display_qty BIGINT NULL AFTER listing_type");
            }
        } catch (Throwable) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_market LIKE 'display_price'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_market ADD COLUMN display_price DECIMAL(12,2) NULL AFTER display_qty");
            }
        } catch (Throwable) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_primary_orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            buyer_username VARCHAR(255) NOT NULL,
            class_id INT NOT NULL,
            shares_requested BIGINT NOT NULL DEFAULT 0,
            units_requested BIGINT NOT NULL DEFAULT 0,
            price_per_unit DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            brex_cash_account_id VARCHAR(128) NULL,
            payment_reference VARCHAR(128) NOT NULL,
            brex_transaction_id VARCHAR(128) NULL,
            stripe_session_id VARCHAR(128) NULL,
            payment_provider VARCHAR(32) NOT NULL DEFAULT 'brex',
            status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_primary_orders_buyer (buyer_username, created_at),
            KEY idx_primary_orders_status (status),
            UNIQUE KEY uniq_primary_orders_ref (payment_reference),
            CONSTRAINT fk_primary_orders_class FOREIGN KEY (class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_primary_orders LIKE 'stripe_session_id'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_primary_orders ADD COLUMN stripe_session_id VARCHAR(128) NULL AFTER brex_transaction_id");
            }
        } catch (Throwable) {}
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM equity_primary_orders LIKE 'payment_provider'");
            if ($cols && $cols->rowCount() === 0) {
                $pdo->exec("ALTER TABLE equity_primary_orders ADD COLUMN payment_provider VARCHAR(32) NOT NULL DEFAULT 'brex' AFTER stripe_session_id");
            }
        } catch (Throwable) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_bid_offers (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            offer_type VARCHAR(32) NOT NULL,
            qty BIGINT NOT NULL DEFAULT 0,
            offered_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_bid_offers_type_status (offer_type, status, created_at),
            KEY idx_bid_offers_user (username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_bid_offer_approvals (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            offer_id BIGINT NOT NULL,
            username VARCHAR(255) NOT NULL,
            decision VARCHAR(16) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_bid_offer_approver (offer_id, username),
            KEY idx_bid_offer_approvals_offer (offer_id, decision, created_at),
            KEY idx_bid_offer_approvals_user (username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_pricing_rules (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            class_id INT NOT NULL,
            price_per_share DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            pricing_strategy VARCHAR(64) NOT NULL DEFAULT 'fixed',
            pricing_params_json LONGTEXT NULL,
            effective_from DATETIME NOT NULL,
            effective_to DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_equity_pricing_class (class_id),
            KEY idx_equity_pricing_effective (effective_from, effective_to),
            CONSTRAINT fk_equity_pricing_class FOREIGN KEY (class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_user_profiles (
            username VARCHAR(255) NOT NULL PRIMARY KEY,
            user_type VARCHAR(32) NOT NULL DEFAULT 'shareholder',
            ordinary_votes_shareholder INT NOT NULL DEFAULT 1,
            ordinary_votes_founder INT NOT NULL DEFAULT 1000,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_equity_user_type (user_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_rights_definitions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_equity_right_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_share_rights (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            class_id INT NOT NULL,
            shares_covered BIGINT NOT NULL DEFAULT 0,
            rights_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_equity_rights_user (username),
            KEY idx_equity_rights_class (class_id),
            CONSTRAINT fk_equity_share_rights_class FOREIGN KEY (class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_share_rights_map (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            class_id INT NOT NULL,
            right_code VARCHAR(64) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_equity_right_map (username, class_id, right_code),
            KEY idx_equity_right_map_user (username),
            KEY idx_equity_right_map_class (class_id),
            CONSTRAINT fk_equity_right_map_class FOREIGN KEY (class_id) REFERENCES equity_classes(id),
            CONSTRAINT fk_equity_right_map_code FOREIGN KEY (right_code) REFERENCES equity_rights_definitions(code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_culture_coin_orders (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            stripe_session_id VARCHAR(128) NULL,
            payment_provider VARCHAR(32) NOT NULL DEFAULT 'stripe',
            tenant_id VARCHAR(255) NOT NULL,
            username VARCHAR(255) NOT NULL,
            user_id BIGINT NULL,
            persona_id VARCHAR(255) NULL,
            persona_name VARCHAR(255) NULL,
            asset_key VARCHAR(255) NOT NULL,
            ticker VARCHAR(32) NULL,
            qty BIGINT NOT NULL DEFAULT 0,
            amount_paid_usd DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            usd_per_unit DECIMAL(14,6) NOT NULL DEFAULT 0.000000,
            discount_pct DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            discount_usd DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status VARCHAR(32) NOT NULL DEFAULT 'credited',
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_culture_orders_session (stripe_session_id),
            KEY idx_culture_orders_user (username, created_at),
            KEY idx_culture_orders_tenant (tenant_id, created_at),
            KEY idx_culture_orders_asset (asset_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        mh_equity_seed_default_classes($pdo);

        try {
            $seed = $pdo->prepare("INSERT IGNORE INTO equity_rights_definitions (code, name) VALUES (?, ?)");
            $seed->execute(['drag_along', 'Drag Along']);
            $seed->execute(['tag_along', 'Tag Along']);
        } catch (Throwable) {}

        try {
            $stmt = $pdo->query("SELECT username, class_id, rights_json FROM equity_share_rights WHERE rights_json IS NOT NULL AND rights_json != ''");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $r) {
                $u = isset($r['username']) ? trim((string)$r['username']) : '';
                $cid = (int)($r['class_id'] ?? 0);
                if ($u === '' || $cid < 1) continue;
                $decoded = mh_equity_json_decode($r['rights_json'] ?? '');
                $codes = [];
                if (isset($decoded['rights']) && is_array($decoded['rights'])) {
                    foreach ($decoded['rights'] as $c) {
                        if (is_string($c) && trim($c) !== '') $codes[] = trim($c);
                    }
                }
                $codes = array_values(array_unique($codes));
                if (empty($codes)) continue;
                $ins = $pdo->prepare("INSERT IGNORE INTO equity_share_rights_map (username, class_id, right_code) VALUES (?, ?, ?)");
                foreach ($codes as $code) {
                    $ins->execute([$u, $cid, $code]);
                }
            }
        } catch (Throwable) {}
    }

    function mh_equity_is_preference_class(PDO $pdo, int $classId): bool {
        try {
            $stmt = $pdo->prepare("SELECT name FROM equity_classes WHERE id = ? LIMIT 1");
            $stmt->execute([$classId]);
            $name = (string)$stmt->fetchColumn();
            $n = strtolower(trim($name));
            return $n !== '' && (strpos($n, 'preference') !== false || strpos($n, 'preferred') !== false);
        } catch (Throwable) {
            return false;
        }
    }

    function mh_equity_user_is_founder(PDO $pdo, string $username): bool {
        $username = trim($username);
        if ($username === '') return false;
        try {
            $stmt = $pdo->prepare("SELECT user_type FROM equity_user_profiles WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $v = $stmt->fetchColumn();
            return is_string($v) && strtolower(trim($v)) === 'founder';
        } catch (Throwable) {
            return false;
        }
    }

    function mh_equity_transfer_preference_rights(PDO $pdo, string $seller, string $buyer, int $classId, int $sharesToTransfer): void {
        if ($sharesToTransfer <= 0) return;
        if ($seller === '' || $buyer === '' || $seller === $buyer) return;
        if (!mh_equity_is_preference_class($pdo, $classId)) return;

        $pdo->beginTransaction();
        try {
            $sellerIsFounder = mh_equity_user_is_founder($pdo, $seller);
            $buyerIsFounder = mh_equity_user_is_founder($pdo, $buyer);
            $founderToFounder = $sellerIsFounder && $buyerIsFounder;

            $stmt = $pdo->prepare("SELECT id, shares_covered FROM equity_share_rights WHERE username = ? AND class_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE");
            $stmt->execute([$seller, $classId]);
            $sellerRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $sellerId = is_array($sellerRow) ? (int)($sellerRow['id'] ?? 0) : 0;
            $sellerCovered = is_array($sellerRow) ? (int)($sellerRow['shares_covered'] ?? 0) : 0;
            $take = min(max(0, $sharesToTransfer), max(0, $sellerCovered));

            if ($take > 0 && $sellerId > 0) {
                $upd = $pdo->prepare("UPDATE equity_share_rights SET shares_covered = shares_covered - ? WHERE id = ? AND username = ?");
                $upd->execute([$take, $sellerId, $seller]);
            }

            if ($founderToFounder && $take > 0) {
                $stmt = $pdo->prepare("SELECT id FROM equity_share_rights WHERE username = ? AND class_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE");
                $stmt->execute([$buyer, $classId]);
                $buyerId = (int)$stmt->fetchColumn();
                if ($buyerId > 0) {
                    $upd = $pdo->prepare("UPDATE equity_share_rights SET shares_covered = shares_covered + ? WHERE id = ? AND username = ?");
                    $upd->execute([$take, $buyerId, $buyer]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO equity_share_rights (username, class_id, shares_covered, rights_json) VALUES (?, ?, ?, NULL)");
                    $ins->execute([$buyer, $classId, $take]);
                }

                $codes = [];
                $stmt = $pdo->prepare("SELECT right_code FROM equity_share_rights_map WHERE username = ? AND class_id = ?");
                $stmt->execute([$seller, $classId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $code = isset($r['right_code']) ? trim((string)$r['right_code']) : '';
                    if ($code !== '') $codes[] = $code;
                }
                $codes = array_values(array_unique($codes));
                if (!empty($codes)) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO equity_share_rights_map (username, class_id, right_code) VALUES (?, ?, ?)");
                    foreach ($codes as $code) {
                        $ins->execute([$buyer, $classId, $code]);
                    }
                }
            } else {
                try {
                    $pdo->prepare("DELETE FROM equity_share_rights_map WHERE username = ? AND class_id = ?")->execute([$buyer, $classId]);
                    $pdo->prepare("DELETE FROM equity_share_rights WHERE username = ? AND class_id = ?")->execute([$buyer, $classId]);
                } catch (Throwable) {}
            }

            try {
                $pdo->prepare("DELETE FROM equity_share_rights WHERE username = ? AND class_id = ? AND shares_covered <= 0")->execute([$seller, $classId]);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM equity_share_rights WHERE username = ? AND class_id = ? AND shares_covered > 0");
                $stmt->execute([$seller, $classId]);
                $cnt = (int)$stmt->fetchColumn();
                if ($cnt < 1) {
                    $pdo->prepare("DELETE FROM equity_share_rights_map WHERE username = ? AND class_id = ?")->execute([$seller, $classId]);
                }
            } catch (Throwable) {}

            $pdo->commit();
        } catch (Throwable) {
            $pdo->rollBack();
        }
    }

    function mh_equity_get_or_create_trading_equity_class(PDO $pdo): int {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        mh_equity_ensure_schema($pdo);
        try {
            $stmt = $pdo->query("SELECT id FROM equity_classes WHERE LOWER(name) LIKE '%ordinary%' OR LOWER(name) LIKE '%common%' ORDER BY id ASC LIMIT 1");
            return (int)$stmt->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    function mh_equity_migrate_trading_to_ordinary(PDO $pdo): void {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        mh_equity_ensure_schema($pdo);
        try {
            $stmt = $pdo->prepare("SELECT id FROM equity_classes WHERE name = ? LIMIT 1");
            $stmt->execute(['Trading Equity']);
            $tradingId = (int)$stmt->fetchColumn();
            if ($tradingId < 1) return;

            $stmt = $pdo->query("SELECT id FROM equity_classes WHERE LOWER(name) LIKE '%ordinary%' OR LOWER(name) LIKE '%common%' ORDER BY id ASC LIMIT 1");
            $ordinaryId = (int)$stmt->fetchColumn();
            if ($ordinaryId < 1) return;

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM equity_ledger WHERE class_id = ? AND units_owned > 0");
            $stmt->execute([$tradingId]);
            $cnt = (int)$stmt->fetchColumn();
            if ($cnt < 1) return;

            $pdo->beginTransaction();
            $rows = $pdo->prepare("SELECT username, units_owned FROM equity_ledger WHERE class_id = ? AND units_owned > 0 FOR UPDATE");
            $rows->execute([$tradingId]);
            $list = $rows->fetchAll(PDO::FETCH_ASSOC);
            $ins = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");
            $zero = $pdo->prepare("UPDATE equity_ledger SET units_owned = 0 WHERE username = ? AND class_id = ?");
            foreach ($list as $r) {
                $u = isset($r['username']) ? trim((string)$r['username']) : '';
                $units = (int)($r['units_owned'] ?? 0);
                if ($u === '' || $units < 1) continue;
                $ins->execute([$u, $ordinaryId, $units, $units]);
                $zero->execute([$u, $tradingId]);
            }
            $pdo->commit();
        } catch (Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    function mh_equity_ensure_conversion_audit_schema(PDO $pdo): void {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS equity_conversions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            from_class_id INT NOT NULL,
            to_class_id INT NOT NULL,
            shares_converted BIGINT NOT NULL,
            units_converted BIGINT NOT NULL,
            declaration_text LONGTEXT NOT NULL,
            declaration_sha256 VARCHAR(64) NOT NULL,
            ip_address VARCHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_equity_conv_user (username, created_at),
            KEY idx_equity_conv_from (from_class_id),
            KEY idx_equity_conv_to (to_class_id),
            CONSTRAINT fk_equity_conv_from FOREIGN KEY (from_class_id) REFERENCES equity_classes(id),
            CONSTRAINT fk_equity_conv_to FOREIGN KEY (to_class_id) REFERENCES equity_classes(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    function getEquityConnection(): PDO {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getConnectionById')) {
            throw new RuntimeException('Database module is not available');
        }
        $pdo = database_getConnectionById('db_69c4a4a7e2d724.12239321');
        if (!$pdo instanceof PDO) {
            if (is_object($pdo) && property_exists($pdo, 'pdo') && $pdo->pdo instanceof PDO) {
                $pdo = $pdo->pdo;
            } elseif (is_array($pdo) && isset($pdo['pdo']) && $pdo['pdo'] instanceof PDO) {
                $pdo = $pdo['pdo'];
            }
        }
        mh_equity_ensure_schema($pdo);
        return $pdo;
    }

    function getEquityConnectionStrict(): PDO {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (!function_exists('database_getConnectionById')) {
            throw new RuntimeException('Database module is not available');
        }
        $pdo = database_getConnectionById('db_69c4a4a7e2d724.12239321');
        if (!$pdo instanceof PDO) {
            if (is_object($pdo) && property_exists($pdo, 'pdo') && $pdo->pdo instanceof PDO) {
                $pdo = $pdo->pdo;
            } elseif (is_array($pdo) && isset($pdo['pdo']) && $pdo['pdo'] instanceof PDO) {
                $pdo = $pdo['pdo'];
            }
        }
        mh_equity_ensure_schema($pdo);
        return $pdo;
    }
}
