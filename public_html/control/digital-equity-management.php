<?php
/**
 * Digital Equity Management (DGCL Section 219(c) Compliant)
 * 
 * Functions:
 * 1. Record keeping of stocks owned (Digital Stock Ledger)
 * 2. Trade records (Blockchain-style Transaction Log)
 * 3. Ownership Allocation & Transfer
 */

if (isset($_GET['api']) && is_string($_GET['api']) && trim($_GET['api']) !== '') {
    if (!defined('CUE_DISABLE_AUTO_UI')) {
        define('CUE_DISABLE_AUTO_UI', true);
    }
    if (!defined('CUE_LAYOUT_MANUAL')) {
        define('CUE_LAYOUT_MANUAL', true);
    }
}

require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/auth_classes.php';

// Force load theme module
if (function_exists('cue_autoload')) {
    call_user_func('cue_autoload', 'theme');
}

// Start Session
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Force Hub Realm for consistent menu
$_SESSION['current_realm'] = 'hub';

// Auth Check (Admin Only for now, or check specific permission)
if (!isset($_SESSION['mh_auth_user'])) {
    if (isset($_GET['api']) && is_string($_GET['api']) && trim($_GET['api']) !== '') {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string)$_SERVER['HTTP_ACCEPT']) : '';
        $xrw = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower(trim((string)$_SERVER['HTTP_X_REQUESTED_WITH'])) : '';
        $expectsJson = strpos($accept, 'application/json') !== false || $xrw === 'xmlhttprequest';
        if (!$expectsJson) {
            $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/digital-equity-management.php';
            header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
            exit;
        }

        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'unauthenticated', 'redirect' => '/auth/login.php']);
        exit;
    } else {
        $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/digital-equity-management.php';
        header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
        exit;
    }
}
$currentUser = $_SESSION['mh_auth_user'];

require_once __DIR__ . '/../auth/kripz_gate.php';
mh_kripz_require('digital_equity_management', false);

// Load DB
// Note: We need TWO connections here.
// 1. Biometrics DB (for User list)
// 2. Equity DB (for Ledger/Transactions)

// A. Biometrics Connection (for fetching users)
try {
    if (function_exists('cue_autoload')) {
        call_user_func('cue_autoload', 'database');
    }
    if (!function_exists('database_getConnectionById')) {
        throw new Exception('database_getConnectionById unavailable');
    }
    $pdoBio = call_user_func('database_getConnectionById', 'biometrics');
} catch (Exception $e) {
    die("Biometrics DB Connection Error: " . $e->getMessage());
}

// B. Equity Connection (Dedicated DB)
require_once __DIR__ . '/../hub/equity/db.php';
try {
    if (!function_exists('getEquityConnection')) {
        throw new Exception('getEquityConnection unavailable');
    }
    $pdo = call_user_func('getEquityConnection');
} catch (Exception $e) {
    die("Equity DB Connection Error: " . $e->getMessage());
}
if (function_exists('mh_equity_ensure_schema')) {
    mh_equity_ensure_schema($pdo);
}

// --- Fetch Data (Classes are needed for allocation) ---
$classes = $pdo->query("SELECT * FROM equity_classes")->fetchAll(PDO::FETCH_ASSOC);
$unitsPerShareByClass = [];
foreach ($classes as $i => $c) {
    $classId = (int)($c['id'] ?? 0);
    $unitsPerShare = (int)($c['fractional_units_per_share'] ?? 400);
    if ($unitsPerShare < 1) $unitsPerShare = 1;
    $classes[$i]['units_per_share'] = $unitsPerShare;
    if ($classId > 0) {
        $unitsPerShareByClass[$classId] = $unitsPerShare;
    }
    if ($classId > 0 && function_exists('mh_equity_get_price_per_share')) {
        $classes[$i]['effective_price_per_share'] = mh_equity_get_price_per_share($pdo, $classId);
    } else {
        $classes[$i]['effective_price_per_share'] = (float)($c['price_per_share'] ?? 0);
    }
}

$preferenceClassId = 0;
$ordinaryClassId = 0;
foreach ($classes as $c) {
    $n = isset($c['name']) ? strtolower(trim((string)$c['name'])) : '';
    if ($ordinaryClassId === 0 && $n !== '' && (strpos($n, 'ordinary') !== false || strpos($n, 'common') !== false)) {
        $ordinaryClassId = (int)($c['id'] ?? 0);
    }
    if ($n !== '' && (strpos($n, 'preference') !== false || strpos($n, 'preferred') !== false)) {
        $preferenceClassId = (int)($c['id'] ?? 0);
        break;
    }
}

// --- Handle Actions ---
$message = '';
$flashKey = 'mh_equity_flash_message';
if (isset($_SESSION[$flashKey]) && is_string($_SESSION[$flashKey]) && trim((string)$_SESSION[$flashKey]) !== '') {
    $message = (string)$_SESSION[$flashKey];
    unset($_SESSION[$flashKey]);
}
if (isset($_GET['api']) && (string)$_GET['api'] === 'user_holdings') {
    header('Content-Type: application/json');
    $u = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
    if ($u === '') {
        echo json_encode(['success' => false, 'error' => 'missing_username']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("
            SELECT l.class_id, l.units_owned, c.name AS class_name, c.fractional_units_per_share
            FROM equity_ledger l
            JOIN equity_classes c ON l.class_id = c.id
            WHERE l.username = ?
        ");
        $stmt->execute([$u]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byClass = [];
        foreach ($rows as $r) {
            $cid = (int)($r['class_id'] ?? 0);
            $units = (int)($r['units_owned'] ?? 0);
            if ($cid < 1) continue;
            $ups = (int)($r['fractional_units_per_share'] ?? 1);
            if ($ups < 1) $ups = 1;
            $byClass[$cid] = [
                'class_id' => $cid,
                'class_name' => (string)($r['class_name'] ?? ''),
                'units_owned' => $units,
                'units_per_share' => $ups,
                'shares_equivalent' => $units / $ups,
            ];
        }
        $profile = [
            'user_type' => 'shareholder',
            'ordinary_votes_shareholder' => 1,
            'ordinary_votes_founder' => 1000,
        ];
        try {
            $stmt = $pdo->prepare("SELECT user_type, ordinary_votes_shareholder, ordinary_votes_founder FROM equity_user_profiles WHERE username = ? LIMIT 1");
            $stmt->execute([$u]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($p)) {
                $profile['user_type'] = isset($p['user_type']) ? (string)$p['user_type'] : $profile['user_type'];
                $profile['ordinary_votes_shareholder'] = isset($p['ordinary_votes_shareholder']) ? (int)$p['ordinary_votes_shareholder'] : $profile['ordinary_votes_shareholder'];
                $profile['ordinary_votes_founder'] = isset($p['ordinary_votes_founder']) ? (int)$p['ordinary_votes_founder'] : $profile['ordinary_votes_founder'];
            }
        } catch (Throwable $e) {}

        $rights = [];
        try {
            $stmt = $pdo->prepare("SELECT class_id, shares_covered FROM equity_share_rights WHERE username = ? ORDER BY id ASC");
            $stmt->execute([$u]);
            $rr = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rr as $r) {
                $cid = (int)($r['class_id'] ?? 0);
                if ($cid < 1) continue;
                $rights[$cid] = [
                    'class_id' => $cid,
                    'shares_covered' => (int)($r['shares_covered'] ?? 0),
                    'rights' => ['rights' => []],
                ];
            }
        } catch (Throwable $e) {}
        try {
            $stmt = $pdo->prepare("SELECT class_id, right_code FROM equity_share_rights_map WHERE username = ? ORDER BY class_id, right_code");
            $stmt->execute([$u]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cid = (int)($r['class_id'] ?? 0);
                $code = isset($r['right_code']) ? trim((string)$r['right_code']) : '';
                if ($cid < 1 || $code === '') continue;
                if (!isset($rights[$cid])) {
                    $rights[$cid] = [
                        'class_id' => $cid,
                        'shares_covered' => 0,
                        'rights' => ['rights' => []],
                    ];
                }
                $rights[$cid]['rights']['rights'][] = $code;
            }
            foreach ($rights as $cid => $v) {
                if (isset($rights[$cid]['rights']['rights']) && is_array($rights[$cid]['rights']['rights'])) {
                    $rights[$cid]['rights']['rights'] = array_values(array_unique($rights[$cid]['rights']['rights']));
                }
            }
        } catch (Throwable $e) {}

        echo json_encode(['success' => true, 'username' => $u, 'by_class' => $byClass, 'profile' => $profile, 'share_rights' => $rights]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'query_failed']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectUser = '';
    $redirectUrlOverride = '';
    
    if ($action === 'bid_offer_vote') {
        $offerId = isset($_POST['offer_id']) ? (int)$_POST['offer_id'] : 0;
        $decision = isset($_POST['decision']) ? trim((string)$_POST['decision']) : '';
        if ($decision !== 'accept' && $decision !== 'reject') {
            $decision = '';
        }

        $qs = [];
        foreach (['bid_user', 'bid_type', 'bid_status'] as $k) {
            $v = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
            if ($v !== '') $qs[$k] = $v;
        }
        $redirectUrlOverride = '/control/digital-equity-management.php' . (!empty($qs) ? ('?' . http_build_query($qs)) : '') . '#bidOffers';

        if ($offerId < 1 || $decision === '') {
            $message = 'Invalid bid offer decision.';
        } else {
            try { $pdo->exec("SET SESSION innodb_lock_wait_timeout = 5"); } catch (Throwable $e) {}
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT id, status, username, offer_type, qty, offered_price FROM equity_bid_offers WHERE id = ? FOR UPDATE");
                $stmt->execute([$offerId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!is_array($row)) {
                    throw new RuntimeException('Offer not found.');
                }
                $status = isset($row['status']) ? (string)$row['status'] : '';
                $offerUser = isset($row['username']) ? trim((string)$row['username']) : '';
                $offerType = isset($row['offer_type']) ? trim((string)$row['offer_type']) : '';
                $offerQty = (int)($row['qty'] ?? 0);
                $offerPrice = (float)($row['offered_price'] ?? 0);
                if ($status !== 'active') {
                    throw new RuntimeException('Offer is not active.');
                }
                if ($offerUser === '') {
                    throw new RuntimeException('Offer has no user.');
                }

                $ins = $pdo->prepare("INSERT INTO equity_bid_offer_approvals (offer_id, username, decision) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE decision = VALUES(decision), updated_at = CURRENT_TIMESTAMP");
                $ins->execute([$offerId, $currentUser, $decision]);

                $acceptCount = 0;
                $rejectCount = 0;
                $stmt = $pdo->prepare("SELECT decision, COUNT(*) AS c FROM equity_bid_offer_approvals WHERE offer_id = ? GROUP BY decision");
                $stmt->execute([$offerId]);
                $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($counts as $c) {
                    $d = isset($c['decision']) ? (string)$c['decision'] : '';
                    $n = isset($c['c']) ? (int)$c['c'] : 0;
                    if ($d === 'accept') $acceptCount = $n;
                    if ($d === 'reject') $rejectCount = $n;
                }

                $newStatus = 'active';
                if ($acceptCount >= 2) {
                    $upd = $pdo->prepare("UPDATE equity_bid_offers SET status = 'accepted', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'active'");
                    $upd->execute([$offerId]);
                    $didTransition = ((int)$upd->rowCount()) > 0;
                    $newStatus = 'accepted';
                    if ($didTransition) {
                        $classId = 0;
                        if (($offerType === 'preferred_equity' || $offerType === 'preferred_equity_coins') && $preferenceClassId > 0) {
                            $classId = (int)$preferenceClassId;
                        } elseif (($offerType === 'ordinary_equity' || $offerType === 'ordinary_equity_coins') && $ordinaryClassId > 0) {
                            $classId = (int)$ordinaryClassId;
                        }

                        if ($classId > 0 && $offerQty > 0) {
                            $unitsPerShare = (int)($unitsPerShareByClass[$classId] ?? 1);
                            if ($unitsPerShare < 1) $unitsPerShare = 1;

                            $isCoinOffer = (strlen($offerType) >= 6 && substr($offerType, -6) === '_coins');
                            $unitsToMint = $isCoinOffer ? $offerQty : ($offerQty * $unitsPerShare);
                            if ($unitsToMint < 1) {
                                throw new RuntimeException('Invalid mint units.');
                            }
                            $pricePerUnit = $isCoinOffer ? $offerPrice : ($offerPrice / $unitsPerShare);
                            if ($pricePerUnit < 0) $pricePerUnit = 0;

                            $timestamp = date('Y-m-d H:i:s');
                            $stmt = $pdo->query("SELECT txn_hash FROM equity_transactions ORDER BY id DESC LIMIT 1 FOR UPDATE");
                            $lastHash = $stmt ? ($stmt->fetchColumn() ?: '') : '';
                            if (!is_string($lastHash) || $lastHash === '') {
                                $lastHash = '0000000000000000000000000000000000000000000000000000000000000000';
                            }

                            $dataString = $lastHash . 'MINT' . $offerUser . $classId . $unitsToMint . $timestamp . 'BID-OFFER-' . $offerId;
                            $newHash = hash('sha256', $dataString);

                            $stmtIns = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type, timestamp) VALUES (?, ?, ?, NULL, ?, ?, ?, 'mint', ?)");
                            $stmtIns->execute([$lastHash, $newHash, $classId, $offerUser, $unitsToMint, $pricePerUnit, $timestamp]);

                            $stmtLed = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");
                            $stmtLed->execute([$offerUser, $classId, $unitsToMint, $unitsToMint]);

                            $stmtProfile = $pdo->prepare("INSERT INTO equity_user_profiles (username, user_type) VALUES (?, 'shareholder') ON DUPLICATE KEY UPDATE user_type = IF(user_type = 'founder', user_type, 'shareholder')");
                            $stmtProfile->execute([$offerUser]);
                        }
                    }
                } elseif ($rejectCount >= 2) {
                    $upd = $pdo->prepare("UPDATE equity_bid_offers SET status = 'rejected', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'active'");
                    $upd->execute([$offerId]);
                    $newStatus = 'rejected';
                }

                $pdo->commit();
                $message = $newStatus === 'active' ? 'Decision recorded.' : ('Offer ' . $newStatus . '.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $message = 'Decision failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'allocate_multi') {
        $recipient = isset($_POST['recipient']) ? (string)$_POST['recipient'] : '';
        $redirectUser = $recipient;
        $sharesByClass = isset($_POST['shares']) && is_array($_POST['shares']) ? $_POST['shares'] : [];

        $currentUnitsByClass = [];
        try {
            $stmt = $pdo->prepare("SELECT class_id, units_owned FROM equity_ledger WHERE username = ?");
            $stmt->execute([$recipient]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cid = (int)($r['class_id'] ?? 0);
                if ($cid > 0) {
                    $currentUnitsByClass[$cid] = (int)($r['units_owned'] ?? 0);
                }
            }
        } catch (Throwable $e) {}

        $allocations = [];
        foreach ($classes as $c) {
            $classId = (int)($c['id'] ?? 0);
            if ($classId <= 0) continue;
            $sharesRaw = $sharesByClass[$classId] ?? null;
            $shares = is_string($sharesRaw) || is_numeric($sharesRaw) ? (float)$sharesRaw : 0.0;
            if ($shares < 0) continue;
            $ups = (int)($unitsPerShareByClass[$classId] ?? 1);
            if ($ups < 1) $ups = 1;
            $targetUnits = (int)max(0, round($shares * $ups));
            $currentUnits = (int)($currentUnitsByClass[$classId] ?? 0);
            $delta = $targetUnits - $currentUnits;
            if ($delta === 0) continue;
            $allocations[] = ['class_id' => $classId, 'delta_units' => $delta];
        }

        if ($recipient === '' || empty($allocations)) {
            $message = 'Invalid allocation request.';
        } else {
            try { @set_time_limit(30); } catch (Throwable $e) {}
            try { $pdo->exec("SET SESSION innodb_lock_wait_timeout = 5"); } catch (Throwable $e) {}
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->query("SELECT txn_hash FROM equity_transactions ORDER BY id DESC LIMIT 1");
                $lastHash = $stmt->fetchColumn() ?: '0000000000000000000000000000000000000000000000000000000000000000';

                foreach ($allocations as $a) {
                    $classId = (int)$a['class_id'];
                    $deltaUnits = (int)$a['delta_units'];
                    $amount = (int)abs($deltaUnits);
                    $txnLabel = $deltaUnits > 0 ? 'MINT' : 'BURN';
                    $timestamp = date('Y-m-d H:i:s');
                    $dataString = $lastHash . $txnLabel . $recipient . $classId . $amount . $timestamp;
                    $newHash = hash('sha256', $dataString);

                    $pricePerUnit = 0.00;
                    if (function_exists('mh_equity_get_price_per_unit')) {
                        $pricePerUnit = (float)mh_equity_get_price_per_unit($pdo, $classId, $timestamp);
                    }

                    if ($deltaUnits > 0) {
                        $stmtIns = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type, timestamp) VALUES (?, ?, ?, NULL, ?, ?, ?, 'mint', ?)");
                        $stmtIns->execute([$lastHash, $newHash, $classId, $recipient, $amount, $pricePerUnit, $timestamp]);

                        $stmtLed = $pdo->prepare("INSERT INTO equity_ledger (username, class_id, units_owned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE units_owned = units_owned + ?");
                        $stmtLed->execute([$recipient, $classId, $amount, $amount]);
                    } else {
                        $stmtCheck = $pdo->prepare("SELECT units_owned FROM equity_ledger WHERE username = ? AND class_id = ? FOR UPDATE");
                        $stmtCheck->execute([$recipient, $classId]);
                        $owned = (int)$stmtCheck->fetchColumn();
                        if ($owned < $amount) {
                            throw new RuntimeException('Cannot reduce below 0 for class ' . $classId);
                        }
                        $stmtIns = $pdo->prepare("INSERT INTO equity_transactions (prev_hash, txn_hash, class_id, sender, recipient, units, price_per_unit, txn_type, timestamp) VALUES (?, ?, ?, ?, NULL, ?, ?, 'burn', ?)");
                        $stmtIns->execute([$lastHash, $newHash, $classId, $recipient, $amount, $pricePerUnit, $timestamp]);
                        $stmtLed = $pdo->prepare("UPDATE equity_ledger SET units_owned = units_owned - ? WHERE username = ? AND class_id = ?");
                        $stmtLed->execute([$amount, $recipient, $classId]);
                    }

                    $lastHash = $newHash;
                }

                $pdo->commit();
                $message = 'Successfully allocated equity.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'save_user_profile') {
        $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
        $redirectUser = $username;
        $userType = isset($_POST['user_type']) ? trim((string)$_POST['user_type']) : 'shareholder';
        $votesShareholder = isset($_POST['ordinary_votes_shareholder']) ? (int)$_POST['ordinary_votes_shareholder'] : 1;
        $votesFounder = isset($_POST['ordinary_votes_founder']) ? (int)$_POST['ordinary_votes_founder'] : 1000;
        if ($username === '') {
            $message = 'Missing user.';
        } else {
            $allowed = ['founder', 'shareholder', 'mvi'];
            if (!in_array($userType, $allowed, true)) {
                $userType = 'shareholder';
            }
            $votesShareholder = max(0, $votesShareholder);
            $votesFounder = max(0, $votesFounder);
            try {
                $stmt = $pdo->prepare("INSERT INTO equity_user_profiles (username, user_type, ordinary_votes_shareholder, ordinary_votes_founder) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_type = VALUES(user_type), ordinary_votes_shareholder = VALUES(ordinary_votes_shareholder), ordinary_votes_founder = VALUES(ordinary_votes_founder)");
                $stmt->execute([$username, $userType, $votesShareholder, $votesFounder]);
                $message = 'User equity profile saved.';
            } catch (Throwable $e) {
                $message = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_user_share_rights') {
        $username = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
        $redirectUser = $username;
        $classId = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
        $sharesCovered = isset($_POST['shares_covered']) ? (int)$_POST['shares_covered'] : 0;
        $rights = isset($_POST['rights']) && is_array($_POST['rights']) ? $_POST['rights'] : [];
        if ($username === '' || $classId < 1) {
            $message = 'Invalid rights update.';
        } else {
            $sharesCovered = max(0, $sharesCovered);
            $maxCovered = 0;
            try {
                $stmt = $pdo->prepare("SELECT units_owned FROM equity_ledger WHERE username = ? AND class_id = ? LIMIT 1");
                $stmt->execute([$username, $classId]);
                $units = (int)$stmt->fetchColumn();
                $ups = (int)($unitsPerShareByClass[$classId] ?? 1);
                if ($ups < 1) $ups = 1;
                $maxCovered = (int)floor(max(0, $units) / $ups);
            } catch (Throwable $e) {
                $maxCovered = 0;
            }
            if ($sharesCovered > $maxCovered) {
                $sharesCovered = $maxCovered;
            }
            $clean = [];
            foreach ($rights as $code => $v) {
                if (!is_string($code)) continue;
                $code = trim($code);
                if ($code === '') continue;
                $clean[] = $code;
            }
            if ($preferenceClassId > 0 && $classId === (int)$preferenceClassId) {
                $ut = 'shareholder';
                try {
                    $stmt = $pdo->prepare("SELECT user_type FROM equity_user_profiles WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    $got = $stmt->fetchColumn();
                    if (is_string($got) && trim($got) !== '') {
                        $ut = strtolower(trim($got));
                    }
                } catch (Throwable $e) {
                    $ut = 'shareholder';
                }
                if ($ut !== 'founder') {
                    $clean = array_values(array_filter($clean, function ($c) {
                        return $c !== 'super_vote';
                    }));
                }
            }
            $json = json_encode(['rights' => array_values(array_unique($clean))], JSON_UNESCAPED_SLASHES);
            $pdo->beginTransaction();
            try {
                $del = $pdo->prepare("DELETE FROM equity_share_rights WHERE username = ? AND class_id = ?");
                $del->execute([$username, $classId]);
                $delMap = $pdo->prepare("DELETE FROM equity_share_rights_map WHERE username = ? AND class_id = ?");
                $delMap->execute([$username, $classId]);

                if ($sharesCovered > 0) {
                    $ins = $pdo->prepare("INSERT INTO equity_share_rights (username, class_id, shares_covered, rights_json) VALUES (?, ?, ?, NULL)");
                    $ins->execute([$username, $classId, $sharesCovered]);
                }
                if (!empty($clean)) {
                    $insMap = $pdo->prepare("INSERT IGNORE INTO equity_share_rights_map (username, class_id, right_code) VALUES (?, ?, ?)");
                    foreach (array_values(array_unique($clean)) as $code) {
                        $insMap->execute([$username, $classId, $code]);
                    }
                }
                $pdo->commit();
                $message = 'Share rights saved.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $message = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'add_right_definition') {
        $name = isset($_POST['right_name']) ? trim((string)$_POST['right_name']) : '';
        if ($name === '') {
            $message = 'Invalid right.';
        } else {
            $code = strtolower($name);
            $code = preg_replace('/[^a-z0-9]+/', '_', $code);
            $code = trim((string)$code, '_');
            if ($code === '') {
                $message = 'Invalid right.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO equity_rights_definitions (code, name) VALUES (?, ?)");
                    $stmt->execute([$code, $name]);
                    $message = 'Right added.';
                } catch (Throwable $e) {
                    $message = 'Error: ' . $e->getMessage();
                }
            }
        }
    }

    if (!headers_sent()) {
        $_SESSION[$flashKey] = (string)$message;
        if (is_string($redirectUrlOverride) && trim($redirectUrlOverride) !== '') {
            $to = (string)$redirectUrlOverride;
        } else {
            $to = '/control/digital-equity-management.php';
            if (is_string($redirectUser) && trim($redirectUser) !== '') {
                $to .= '?edit_user=' . rawurlencode((string)$redirectUser);
            }
        }
        header('Location: ' . $to, true, 303);
        exit;
    }
}

// --- Fetch Data ---
// 3. Users (for selector) - only users with PIN or passkey, exclude placeholders
$userRows = $pdoBio->query("SELECT username, name, pin_hash FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$passkeyAuth = null;
try {
    $passkeyAuth = class_exists('MetaPasskeyAuth') ? new MetaPasskeyAuth() : null;
} catch (Throwable $e) {
    $passkeyAuth = null;
}
$users = [];
foreach ($userRows as $row) {
    $username = isset($row['username']) ? trim((string)$row['username']) : '';
    if ($username === '') {
        continue;
    }
    if (preg_match('/^(MetaHuman_[0-9a-f]{16}|anon_[0-9a-f]+)$/i', $username)) {
        continue;
    }
    $hasPin = isset($row['pin_hash']) && is_string($row['pin_hash']) && trim($row['pin_hash']) !== '';
    $hasPasskey = false;
    if ($passkeyAuth) {
        try {
            $hasPasskey = (bool)$passkeyAuth->hasUserPasskeys($username);
        } catch (Throwable $e) {
            $hasPasskey = false;
        }
    }
    if (!$hasPin && !$hasPasskey) {
        continue;
    }
    $displayName = isset($row['name']) ? trim((string)$row['name']) : '';
    $users[] = ['username' => $username, 'name' => $displayName];
}

$editUser = isset($_GET['edit_user']) ? trim((string)$_GET['edit_user']) : '';

$rightsDefs = [];
try {
    $rightsDefs = $pdo->query("SELECT code, name FROM equity_rights_definitions ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $rightsDefs = [];
}

$bidTypes = [
    'preferred_equity' => 'Preferred Equity',
    'ordinary_equity' => 'Ordinary Equity',
    'preferred_equity_coins' => 'Preferred Equity Coins',
    'ordinary_equity_coins' => 'Ordinary Equity Coins',
    'culture_meme_coins' => 'Culture / Meme Coins',
    'stable_coins' => 'Stable Coins',
];

$bidFilterUser = isset($_GET['bid_user']) ? trim((string)$_GET['bid_user']) : '';
$bidFilterType = isset($_GET['bid_type']) ? trim((string)$_GET['bid_type']) : '';
$bidFilterStatus = isset($_GET['bid_status']) ? trim((string)$_GET['bid_status']) : 'active';
if ($bidFilterStatus === '') $bidFilterStatus = 'active';

$bidOfferers = [];
try {
    $bidOfferers = $pdo->query("SELECT DISTINCT username FROM equity_bid_offers ORDER BY username ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($bidOfferers)) $bidOfferers = [];
} catch (Throwable $e) {
    $bidOfferers = [];
}

$bidOffersReview = [];
$bidApprovalsByOffer = [];
try {
    $conds = [];
    $params = [];
    if ($bidFilterUser !== '') {
        $conds[] = 'o.username = ?';
        $params[] = $bidFilterUser;
    }
    if ($bidFilterType !== '') {
        $conds[] = 'o.offer_type = ?';
        $params[] = $bidFilterType;
    }
    if ($bidFilterStatus !== '' && $bidFilterStatus !== 'all') {
        $conds[] = 'o.status = ?';
        $params[] = $bidFilterStatus;
    }
    $where = !empty($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';

    $stmt = $pdo->prepare("
        SELECT
            o.id, o.username, o.offer_type, o.qty, o.offered_price, o.status, o.created_at,
            COALESCE(SUM(CASE WHEN a.decision = 'accept' THEN 1 ELSE 0 END), 0) AS accept_count,
            COALESCE(SUM(CASE WHEN a.decision = 'reject' THEN 1 ELSE 0 END), 0) AS reject_count
        FROM equity_bid_offers o
        LEFT JOIN equity_bid_offer_approvals a ON a.offer_id = o.id
        $where
        GROUP BY o.id
        ORDER BY o.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $bidOffersReview = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($bidOffersReview)) $bidOffersReview = [];

    $ids = [];
    foreach ($bidOffersReview as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT offer_id, username, decision, created_at FROM equity_bid_offer_approvals WHERE offer_id IN ($ph) ORDER BY created_at ASC");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            foreach ($rows as $a) {
                $oid = (int)($a['offer_id'] ?? 0);
                if ($oid < 1) continue;
                if (!isset($bidApprovalsByOffer[$oid])) $bidApprovalsByOffer[$oid] = [];
                $bidApprovalsByOffer[$oid][] = $a;
            }
        }
    }
} catch (Throwable $e) {
    $bidOffersReview = [];
    $bidApprovalsByOffer = [];
}

require_once __DIR__ . '/../templates/global-ui/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Equity Management | Meta Humans</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700&family=Rajdhani:wght@300;400;600&display=swap" rel="stylesheet">
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        #hamburger-menu { position: fixed; top: 20px; left: 20px; z-index: 1000; }
        
        :root {
            --primary: #00d4ff;
            --bg-dark: #1a1a1a;
            --glass: rgba(255, 255, 255, 0.05);
            --border: rgba(0, 212, 255, 0.2);
            --text-main: #e0e0e0;
        }
        :root { color-scheme: dark; }
        html { color-scheme: dark; }
        html, body { background-color: #1a1a1a !important; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .main-content, .page-content, #page-wrapper { background-color: #1a1a1a !important; }
        .container { max-width: 1400px; margin: 0 auto; padding: 40px 20px; }
        h1, h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); }
        
        .grid { display: grid; grid-template-columns: 1fr; gap: 30px; align-items: start; }
        
        .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; }
        .panel h2 { margin-top: 0; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); }
        th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; }
        
        .hash { font-family: monospace; font-size: 0.8rem; color: #aaa; }
        
        label { display: block; margin: 12px 0 6px; font-weight: 600; letter-spacing: 0.2px; }
        form > label:first-of-type { margin-top: 0; }

        .container select, .container input, .container button {
            background: #2b2b2b !important;
            border: 1px solid var(--border);
            color: var(--primary) !important;
            padding: 11px 12px;
            width: 100%;
            border-radius: 6px;
            box-sizing: border-box;
        }
        .container select { -webkit-appearance: none; appearance: none; color-scheme: dark; }
        .container option, .container optgroup { background: #2b2b2b !important; color: var(--primary) !important; }
        .container select, .container input { margin: 0; }
        .container button { margin-top: 12px; background: var(--primary); color: #000; font-weight: bold; cursor: pointer; text-transform: uppercase; }
        .container button:hover { opacity: 0.9; }
        
        .alert { background: rgba(0, 212, 255, 0.1); border: 1px solid var(--primary); color: var(--primary); padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        
        .dgcl-notice { font-size: 0.8rem; color: #888; margin-top: 5px; border-left: 2px solid #555; padding-left: 10px; }

        .equity-class-card { border: 1px solid rgba(0, 212, 255, 0.15); border-radius: 10px; padding: 14px; margin-top: 14px; }
        .equity-class-head { display:flex; justify-content:space-between; gap:10px; align-items:baseline; }
        .equity-class-name { color: var(--primary); font-weight: 700; }
        .equity-class-meta { color:#9aa; font-size:0.85rem; }
        .equity-class-inputs { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 12px; }
        .equity-class-current { margin-top: 8px; color: #aab; font-size: 0.85rem; }

        .btn-secondary { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
        .btn-secondary:hover { opacity: 1; background: rgba(0, 212, 255, 0.1); }

        #loaderOverlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.55); z-index: 9999; }
        #loaderOverlay .box { background: rgba(30,30,30,0.95); border: 1px solid var(--border); border-radius: 10px; padding: 16px 18px; color: #fff; font-family: 'Orbitron', sans-serif; }
        #noticeWidget { display: none; }
    </style>
</head>
<body>
    <?php if (function_exists('renderGlobalHeader')) { renderGlobalHeader(); } ?>
    
    <div class="container">
        <h1>Digital Equity Management</h1>
        <p>Meta Humans LTD - Digital Stock Ledger (DGCL § 219(c) Compliant)</p>
        
        <?php if ($message): ?>
            <div class="alert"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <div id="noticeWidget" class="alert" style="display:none;"></div>

        <div class="panel" style="margin-top: 20px;">
            <?php foreach ($classes as $c): ?>
                <?php
                    $effectivePrice = (float)($c['effective_price_per_share'] ?? $c['price_per_share'] ?? 0);
                    $strategy = isset($c['pricing_strategy']) ? (string)$c['pricing_strategy'] : '';
                    $authorizedShares = (int)($c['total_shares'] ?? 0);
                    $classId = (int)($c['id'] ?? 0);
                    $unitsPerShare = (int)($c['units_per_share'] ?? $c['fractional_units_per_share'] ?? 400);
                    if ($unitsPerShare < 1) $unitsPerShare = 1;
                    $issuedShares = 0.0;
                    if ($classId > 0) {
                        $stmt = $pdo->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM equity_ledger WHERE class_id = ?");
                        $stmt->execute([$classId]);
                        $issuedUnits = (int)$stmt->fetchColumn();
                        $issuedShares = $issuedUnits / $unitsPerShare;
                    }
                ?>
                <div style="margin-bottom: 15px;">
                    <div style="color: var(--primary); font-weight: bold;"><?php echo htmlspecialchars((string)($c['name'] ?? '')); ?></div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span>Authorized Shares:</span>
                        <span id="ms_auth_<?php echo $classId; ?>"><?php echo number_format($authorizedShares); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span>Issued Shares:</span>
                        <span id="ms_issued_<?php echo $classId; ?>"><?php echo number_format($issuedShares, 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span>Coins / Share:</span>
                        <span><?php echo number_format($unitsPerShare); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span>Price/Share:</span>
                        <span id="ms_pps_<?php echo $classId; ?>">$<?php echo number_format($effectivePrice, 2); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                        <span>Price/Coin:</span>
                        <span id="ms_ppc_<?php echo $classId; ?>">$<?php echo number_format($effectivePrice / $unitsPerShare, 4); ?></span>
                    </div>
                    <?php if ($strategy !== '' && $strategy !== 'fixed'): ?>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                            <span>Strategy:</span>
                            <span><?php echo htmlspecialchars($strategy); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="panel" style="margin-top: 20px;">
            <h2>Records</h2>
            <div style="display:flex; gap: 12px; flex-wrap: wrap; margin-top: 12px;">
                <a class="btn-secondary" style="display:inline-block; padding:10px 12px; border-radius:6px; text-decoration:none;" href="/control/records/stock-ledger.php" target="_blank" rel="noopener">Stock Ledger</a>
                <a class="btn-secondary" style="display:inline-block; padding:10px 12px; border-radius:6px; text-decoration:none;" href="/control/records/trade-records.php" target="_blank" rel="noopener">Trade Records</a>
                <a class="btn-secondary" style="display:inline-block; padding:10px 12px; border-radius:6px; text-decoration:none;" href="/control/records/equity-classes.php" target="_blank" rel="noopener">Company Equity Classes</a>
            </div>
        </div>

        <div class="panel" id="bidOffers">
            <h2>Bid Offers Review</h2>
            <form method="GET" style="display:flex; gap: 12px; flex-wrap: wrap; align-items:flex-end;">
                <div style="min-width: 220px; flex: 1;">
                    <label>Offerer</label>
                    <select name="bid_user">
                        <option value="">All</option>
                        <?php foreach ($bidOfferers as $ou): ?>
                            <?php $ou = is_string($ou) ? trim($ou) : ''; if ($ou === '') continue; ?>
                            <option value="<?php echo htmlspecialchars($ou); ?>" <?php echo $bidFilterUser === $ou ? 'selected' : ''; ?>><?php echo htmlspecialchars($ou); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width: 240px; flex: 1;">
                    <label>Asset</label>
                    <select name="bid_type">
                        <option value="">All</option>
                        <?php foreach ($bidTypes as $k => $lbl): ?>
                            <option value="<?php echo htmlspecialchars((string)$k); ?>" <?php echo $bidFilterType === (string)$k ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width: 200px;">
                    <label>Status</label>
                    <select name="bid_status">
                        <option value="active" <?php echo $bidFilterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="accepted" <?php echo $bidFilterStatus === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="rejected" <?php echo $bidFilterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="all" <?php echo $bidFilterStatus === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>
                <div style="min-width: 160px;">
                    <button type="submit" style="width:auto; margin-top:0;">Filter</button>
                </div>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Offerer</th>
                        <th>Asset</th>
                        <th>Qty</th>
                        <th>Per asset price</th>
                        <th>Total (USD)</th>
                        <th>Status</th>
                        <th>Approvals</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bidOffersReview as $bo): ?>
                        <?php
                            $offerId = (int)($bo['id'] ?? 0);
                            $t = isset($bo['offer_type']) ? (string)$bo['offer_type'] : '';
                            $label = isset($bidTypes[$t]) ? (string)$bidTypes[$t] : $t;
                            $status = isset($bo['status']) ? (string)$bo['status'] : '';
                            $qty = (int)($bo['qty'] ?? 0);
                            $price = (float)($bo['offered_price'] ?? 0);
                            $total = (float)$qty * (float)$price;
                            $acceptCount = (int)($bo['accept_count'] ?? 0);
                            $rejectCount = (int)($bo['reject_count'] ?? 0);
                            $apRows = isset($bidApprovalsByOffer[$offerId]) && is_array($bidApprovalsByOffer[$offerId]) ? $bidApprovalsByOffer[$offerId] : [];
                            $acceptBy = [];
                            $rejectBy = [];
                            $myDecision = '';
                            foreach ($apRows as $ap) {
                                $au = isset($ap['username']) ? trim((string)$ap['username']) : '';
                                $ad = isset($ap['decision']) ? (string)$ap['decision'] : '';
                                if ($au !== '' && $ad === 'accept') $acceptBy[] = $au;
                                if ($au !== '' && $ad === 'reject') $rejectBy[] = $au;
                                if ($au !== '' && strcasecmp($au, (string)$currentUser) === 0) $myDecision = $ad;
                            }
                            $acceptBy = array_values(array_unique($acceptBy));
                            $rejectBy = array_values(array_unique($rejectBy));
                            $isActive = ($status === 'active');
                        ?>
                        <tr>
                            <td><?php echo $offerId; ?></td>
                            <td><?php echo htmlspecialchars((string)($bo['username'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($label); ?></td>
                            <td><?php echo number_format($qty); ?></td>
                            <td>$<?php echo number_format($price, 2); ?></td>
                            <td>$<?php echo number_format($total, 2); ?></td>
                            <td><?php echo htmlspecialchars($status); ?></td>
                            <td>
                                <div style="display:grid; gap: 4px; font-size:0.9rem;">
                                    <div><span style="color:var(--primary); font-weight:700;">Accept:</span> <?php echo $acceptCount; ?><?php echo !empty($acceptBy) ? (' · ' . htmlspecialchars(implode(', ', $acceptBy))) : ''; ?></div>
                                    <div><span style="color:#ff6666; font-weight:700;">Reject:</span> <?php echo $rejectCount; ?><?php echo !empty($rejectBy) ? (' · ' . htmlspecialchars(implode(', ', $rejectBy))) : ''; ?></div>
                                    <?php if ($myDecision !== ''): ?>
                                        <div style="color:#aaa;">You: <?php echo htmlspecialchars($myDecision); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <form method="POST" style="display:inline-block;">
                                    <input type="hidden" name="action" value="bid_offer_vote">
                                    <input type="hidden" name="offer_id" value="<?php echo $offerId; ?>">
                                    <input type="hidden" name="decision" value="accept">
                                    <input type="hidden" name="bid_user" value="<?php echo htmlspecialchars($bidFilterUser); ?>">
                                    <input type="hidden" name="bid_type" value="<?php echo htmlspecialchars($bidFilterType); ?>">
                                    <input type="hidden" name="bid_status" value="<?php echo htmlspecialchars($bidFilterStatus); ?>">
                                    <button type="submit" <?php echo $isActive ? '' : 'disabled'; ?> style="width:auto; margin-top:0; padding:8px 10px;">Accept</button>
                                </form>
                                <form method="POST" style="display:inline-block; margin-left: 8px;">
                                    <input type="hidden" name="action" value="bid_offer_vote">
                                    <input type="hidden" name="offer_id" value="<?php echo $offerId; ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <input type="hidden" name="bid_user" value="<?php echo htmlspecialchars($bidFilterUser); ?>">
                                    <input type="hidden" name="bid_type" value="<?php echo htmlspecialchars($bidFilterType); ?>">
                                    <input type="hidden" name="bid_status" value="<?php echo htmlspecialchars($bidFilterStatus); ?>">
                                    <button type="submit" <?php echo $isActive ? '' : 'disabled'; ?> style="width:auto; margin-top:0; padding:8px 10px; background:#ff4444; color:#000;">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bidOffersReview)): ?>
                        <tr><td colspan="9" style="text-align:center; color:#aaa;">No bid offers found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="dgcl-notice" style="margin-top: 12px;">
                An offer becomes accepted or rejected once it receives at least 2 distinct KripzMasters votes for that decision.
            </div>
        </div>

        <div class="panel" id="allocateEquity">
            <h2>User Allocate Equity</h2>
            <p style="font-size:0.9rem; color:#aaa;">Set target shares per equity class for the selected user.</p>
            <form method="POST" id="allocationForm">
                <input type="hidden" name="action" value="allocate_multi">
                <label>Recipient User</label>
                <select id="recipientSelect" name="recipient" required>
                    <option value="">Select User...</option>
                    <?php foreach ($users as $u): ?>
                        <?php
                            $uname = isset($u['username']) ? (string)$u['username'] : '';
                            $rname = isset($u['name']) ? trim((string)$u['name']) : '';
                            $label = $rname !== '' ? ($rname . ' (' . $uname . ')') : $uname;
                        ?>
                        <option value="<?php echo htmlspecialchars($uname); ?>" <?php echo $editUser !== '' && $uname === $editUser ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <div>
                    <?php foreach ($classes as $c): ?>
                        <?php
                            $classId = (int)($c['id'] ?? 0);
                            $effectivePrice = (float)($c['effective_price_per_share'] ?? $c['price_per_share'] ?? 0);
                            $unitsPerShare = (int)($c['units_per_share'] ?? $c['fractional_units_per_share'] ?? 400);
                            if ($unitsPerShare < 1) $unitsPerShare = 1;
                        ?>
                        <div class="equity-class-card">
                            <div class="equity-class-head">
                                <div class="equity-class-name"><?php echo htmlspecialchars((string)($c['name'] ?? '')); ?></div>
                                <div class="equity-class-meta" id="alloc_meta_<?php echo $classId; ?>"><?php echo number_format($unitsPerShare); ?> coins/share · $<?php echo number_format($effectivePrice, 2); ?>/share</div>
                            </div>
                            <div class="equity-class-current" id="current_<?php echo $classId; ?>">Current: 0.00 shares (0 coins)</div>
                            <div class="equity-class-inputs">
                                <div>
                                    <label>Target Shares</label>
                                    <input class="equityShares" type="number" min="0" step="0.01" placeholder="0" data-class-id="<?php echo $classId; ?>" data-units-per-share="<?php echo (int)$unitsPerShare; ?>" name="shares[<?php echo $classId; ?>]">
                                </div>
                                <div>
                                    <label>Qty (Equity Coins)</label>
                                    <input id="coins_<?php echo $classId; ?>" type="number" min="0" step="1" placeholder="0" readonly>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit">Allocate & Record</button>
            </form>
        </div>

        <div class="panel" id="ordinaryEquityRights">
            <h2>User Ordinary Equity Rights</h2>
            <div class="dgcl-notice" id="rightsAutosaveStatus">Autosave: idle</div>
            <div class="dgcl-notice" id="selectedUserSummary">Select a user to manage voting and preference rights.</div>

            <form method="POST" id="userProfileForm" class="rightsForm">
                <input type="hidden" name="action" value="save_user_profile">
                <input type="hidden" name="username" id="rightsUsernameProfile" value="">
                <label>User Type</label>
                <select name="user_type" id="userTypeSelect">
                    <option value="founder">founder</option>
                    <option value="shareholder" selected>shareholder</option>
                    <option value="mvi">mvi</option>
                </select>
                <div id="votesShareholderRow">
                    <label>Votes / Ordinary Share (Shareholder)</label>
                    <input type="number" name="ordinary_votes_shareholder" id="votesShareholder" min="0" step="1" value="1">
                </div>
                <div id="votesFounderRow">
                    <label>Votes / Ordinary Share (Founder)</label>
                    <input type="number" name="ordinary_votes_founder" id="votesFounder" min="0" step="1" value="1000">
                </div>
            </form>
        </div>

        <div class="panel" id="preferenceEquityRightsPanel">
            <h2>User Preference Equity Rights</h2>
            <div class="dgcl-notice">User Type</div>
            <select id="userTypeSelectPref">
                <option value="founder">founder</option>
                <option value="shareholder" selected>shareholder</option>
            </select>
            <div class="dgcl-notice" id="prefOwnedSummary" style="margin-top: 8px;">Owned: 0 full shares</div>

            <?php if ($preferenceClassId > 0): ?>
                <form method="POST" id="preferenceRightsForm" class="rightsForm" style="margin-top: 18px;">
                    <input type="hidden" name="action" value="save_user_share_rights">
                    <input type="hidden" name="username" id="rightsUsernamePref" value="">
                    <input type="hidden" name="class_id" value="<?php echo (int)$preferenceClassId; ?>">
                    <label>Preference Shares Covered (full shares only)</label>
                    <input type="number" name="shares_covered" id="prefSharesCovered" min="0" step="1" value="0">
                    <label>Rights</label>
                    <div id="prefRightsOptions" style="display:flex; gap:12px; flex-wrap:wrap; margin-top: 6px;">
                        <?php foreach ($rightsDefs as $rd): ?>
                            <?php $code = isset($rd['code']) ? (string)$rd['code'] : ''; ?>
                            <?php $name = isset($rd['name']) ? (string)$rd['name'] : $code; ?>
                            <?php if ($code === '') continue; ?>
                            <label style="display:flex; align-items:center; gap:8px; margin: 0;">
                                <input type="checkbox" name="rights[<?php echo htmlspecialchars($code); ?>]" value="1" style="width:auto;">
                                <?php echo htmlspecialchars($name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="dgcl-notice" style="margin-top: 10px;">Selected Rights</div>
                    <div id="prefSelectedRights" style="display:flex; gap:10px; flex-wrap:wrap; margin-top: 6px;"></div>
                </form>

                <button type="button" id="toggleAddRightForm" class="btn-secondary" style="margin-top: 18px;">Add New Right Type</button>
                <form method="POST" id="addRightForm" style="display:none; margin-top: 12px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 18px;">
                    <input type="hidden" name="action" value="add_right_definition">
                    <label>Add Preference Right Type</label>
                    <input type="text" name="right_name" placeholder="e.g. Drag Along">
                    <button type="submit">Add Right</button>
                </form>
            <?php else: ?>
                <div class="dgcl-notice" style="margin-top: 12px;">No Preference Equity class found. Create it in Company Equity Classes to enable rights.</div>
            <?php endif; ?>
        </div>

    </div>
    
    <?php if (function_exists('renderGlobalFooter')) { renderGlobalFooter(); } ?>
    <div id="loaderOverlay"><div class="box">Loading…</div></div>
    <script>
        (function () {
            const PREFERENCE_CLASS_ID = <?php echo (int)$preferenceClassId; ?>;
            const ORDINARY_CLASS_ID = <?php echo (int)$ordinaryClassId; ?>;
            const UNITS_PER_SHARE_BY_CLASS = <?php echo json_encode($unitsPerShareByClass, JSON_UNESCAPED_SLASHES); ?>;
            let lastFullPrefShares = 0;

            const shareInputs = document.querySelectorAll('input.equityShares');
            shareInputs.forEach(function (el) {
                el.addEventListener('input', function () {
                    const classId = el.getAttribute('data-class-id');
                    const unitsPerShare = parseInt(el.getAttribute('data-units-per-share') || '0', 10) || 0;
                    const shares = parseFloat(el.value || '0') || 0;
                    const coins = Math.max(0, Math.round(shares * (unitsPerShare > 0 ? unitsPerShare : 1)));
                    const coinsEl = classId ? document.getElementById('coins_' + classId) : null;
                    if (coinsEl) {
                        coinsEl.value = String(coins);
                    }
                });
            });

            const recipient = document.getElementById('recipientSelect');
            const loaderOverlay = document.getElementById('loaderOverlay');
            const noticeWidget = document.getElementById('noticeWidget');
            const selectedUserSummary = document.getElementById('selectedUserSummary');
            const userTypeSelect = document.getElementById('userTypeSelect');
            const userTypeSelectPref = document.getElementById('userTypeSelectPref');
            const votesShareholderRow = document.getElementById('votesShareholderRow');
            const votesFounderRow = document.getElementById('votesFounderRow');
            const votesShareholder = document.getElementById('votesShareholder');
            const votesFounder = document.getElementById('votesFounder');
            const prefForm = document.getElementById('preferenceRightsForm');
            const prefPanel = document.getElementById('preferenceEquityRightsPanel');
            const prefOptions = document.getElementById('prefRightsOptions');
            const prefSharesCoveredEl = document.getElementById('prefSharesCovered');
            const addRightForm = document.getElementById('addRightForm');
            const prefOwnedSummary = document.getElementById('prefOwnedSummary');
            const prefSelectedRights = document.getElementById('prefSelectedRights');

            function setNotice(text) {
                if (!noticeWidget) return;
                if (!text || String(text).trim() === '') {
                    noticeWidget.style.display = 'none';
                    noticeWidget.textContent = '';
                    return;
                }
                noticeWidget.textContent = String(text);
                noticeWidget.style.display = 'block';
            }

            function setLoading(isLoading, text) {
                if (loaderOverlay) {
                    loaderOverlay.style.display = isLoading ? 'flex' : 'none';
                }
                if (isLoading && text) {
                    setNotice(text);
                }
            }

            function setUserTypeUI(userType) {
                const t = (userType || 'shareholder').toLowerCase();
                if (votesShareholderRow) votesShareholderRow.style.display = (t === 'shareholder') ? 'block' : 'none';
                if (votesFounderRow) votesFounderRow.style.display = (t === 'founder') ? 'block' : 'none';
                if (t === 'mvi') {
                    if (votesShareholderRow) votesShareholderRow.style.display = 'none';
                    if (votesFounderRow) votesFounderRow.style.display = 'none';
                }

                const prefAllowed = t !== 'mvi';
                if (prefPanel) prefPanel.style.display = prefAllowed ? 'block' : 'none';
                if (prefForm) prefForm.style.display = prefAllowed ? 'block' : 'none';
                if (prefOptions) {
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                        cb.disabled = !prefAllowed;
                    });
                }
                if (prefSharesCoveredEl) prefSharesCoveredEl.disabled = !prefAllowed;
            }

            function computeVotes(userType, fullOrdShares) {
                const t = (userType || 'shareholder').toLowerCase();
                const shares = Math.max(0, parseInt(String(fullOrdShares || 0), 10) || 0);
                if (t === 'mvi') return 0;
                if (t === 'founder') {
                    const v = votesFounder ? (parseInt(votesFounder.value || '1000', 10) || 1000) : 1000;
                    return shares * Math.max(0, v);
                }
                const v = votesShareholder ? (parseInt(votesShareholder.value || '1', 10) || 1) : 1;
                return shares * Math.max(0, v);
            }

            function updatePrefSelectedRightsUI() {
                if (!prefSelectedRights || !prefOptions) return;
                prefSelectedRights.innerHTML = '';
                const selected = [];
                prefOptions.querySelectorAll('input[type="checkbox"][name^="rights["]').forEach(function (cb) {
                    if (!cb.checked) return;
                    const label = cb.closest('label');
                    const name = label ? String(label.textContent || '').trim() : '';
                    const m = String(cb.getAttribute('name') || '').match(/^rights\\[(.+)\\]$/);
                    const code = m && m[1] ? m[1] : '';
                    if (code === '') return;
                    selected.push({ code: code, name: name !== '' ? name : code });
                });
                if (selected.length === 0) {
                    const el = document.createElement('div');
                    el.style.color = '#9aa';
                    el.textContent = 'None';
                    prefSelectedRights.appendChild(el);
                    return;
                }
                selected.forEach(function (r) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn-secondary';
                    btn.style.marginTop = '0';
                    btn.textContent = 'Remove ' + r.name;
                    btn.setAttribute('data-right-code', r.code);
                    prefSelectedRights.appendChild(btn);
                });
            }

            function applySuperVoteRule(fullPrefShares) {
                if (!prefOptions) return;
                const cb = prefOptions.querySelector('input[type="checkbox"][name="rights[super_vote]"]');
                if (!cb) return;
                const t = userTypeSelect ? String(userTypeSelect.value || 'shareholder').toLowerCase() : 'shareholder';
                const allowed = t === 'founder' && (parseInt(String(fullPrefShares || 0), 10) || 0) > 0;
                cb.disabled = !allowed;
                if (!allowed && cb.checked) {
                    cb.checked = false;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            function setPreferenceDefaults(userType, fullPrefShares) {
                if (!prefForm || !prefOptions) return;
                const t = (userType || 'shareholder').toLowerCase();
                if (t === 'mvi') {
                    if (prefSharesCoveredEl) prefSharesCoveredEl.value = '0';
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
                    if (prefSharesCoveredEl) prefSharesCoveredEl.dispatchEvent(new Event('input', { bubbles: true }));
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.dispatchEvent(new Event('change', { bubbles: true })); });
                    return;
                }
                if (t === 'founder') {
                    if (prefSharesCoveredEl) prefSharesCoveredEl.value = String(Math.max(0, parseInt(String(fullPrefShares || 0), 10) || 0));
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = true; });
                    if (prefSharesCoveredEl) prefSharesCoveredEl.dispatchEvent(new Event('input', { bubbles: true }));
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.dispatchEvent(new Event('change', { bubbles: true })); });
                    return;
                }
                if (t === 'shareholder') {
                    if (prefSharesCoveredEl) prefSharesCoveredEl.value = '0';
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });
                    if (prefSharesCoveredEl) prefSharesCoveredEl.dispatchEvent(new Event('input', { bubbles: true }));
                    prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) { cb.dispatchEvent(new Event('change', { bubbles: true })); });
                }
            }

            async function loadUserHoldings(username) {
                if (!username) {
                    document.querySelectorAll('[id^="current_"]').forEach(el => el.textContent = 'Current: 0.00 shares (0 coins)');
                    const ru1 = document.getElementById('rightsUsernameProfile');
                    const ru2 = document.getElementById('rightsUsernamePref');
                    if (ru1) ru1.value = '';
                    if (ru2) ru2.value = '';
                    if (selectedUserSummary) selectedUserSummary.textContent = 'Select a user to manage voting and preference rights.';
                    setUserTypeUI('mvi');
                    setNotice('');
                    return;
                }
                setLoading(true, 'Loading user ' + username + '…');
                const url = new URL(window.location.href);
                url.searchParams.set('api', 'user_holdings');
                url.searchParams.set('username', username);
                const res = await fetch(url.toString(), { credentials: 'same-origin' });
                if (res.status === 401) {
                    const redirect = '/auth/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search + window.location.hash);
                    window.location.href = redirect;
                    return;
                }
                let data = null;
                try {
                    data = await res.json();
                } catch (e) {
                    setLoading(false);
                    return;
                }
                if (data && data.error === 'unauthenticated') {
                    const redirect = '/auth/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search + window.location.hash);
                    window.location.href = redirect;
                    return;
                }
                if (!data || !data.success) return;
                const byClass = data.by_class || {};
                Object.keys(byClass).forEach(function (k) {
                    const cid = String(k);
                    const row = byClass[k];
                    const sharesEq = parseFloat(row.shares_equivalent || 0) || 0;
                    const units = parseInt(row.units_owned || 0, 10) || 0;
                    const currentEl = document.getElementById('current_' + cid);
                    if (currentEl) {
                        currentEl.textContent = 'Current: ' + sharesEq.toFixed(2) + ' shares (' + units.toLocaleString() + ' coins)';
                    }
                    const sharesInput = document.querySelector('input.equityShares[data-class-id="' + cid + '"]');
                    if (sharesInput) {
                        sharesInput.value = sharesEq.toFixed(2);
                        sharesInput.dispatchEvent(new Event('input'));
                    }
                });
                document.querySelectorAll('input.equityShares').forEach(function (el) {
                    const cid = el.getAttribute('data-class-id');
                    if (!cid) return;
                    if (Object.prototype.hasOwnProperty.call(byClass, cid)) return;
                    const currentEl = document.getElementById('current_' + cid);
                    if (currentEl) currentEl.textContent = 'Current: 0.00 shares (0 coins)';
                    el.value = '0';
                    el.dispatchEvent(new Event('input'));
                });

                const ru1 = document.getElementById('rightsUsernameProfile');
                const ru2 = document.getElementById('rightsUsernamePref');
                if (ru1) ru1.value = username;
                if (ru2) ru2.value = username;

                const profile = data.profile || {};
                if (userTypeSelect && typeof profile.user_type === 'string') {
                    userTypeSelect.value = profile.user_type;
                }
                if (userTypeSelectPref && userTypeSelect && typeof userTypeSelect.value === 'string') {
                    userTypeSelectPref.value = userTypeSelect.value;
                }
                if (votesShareholder) votesShareholder.value = String(parseInt(profile.ordinary_votes_shareholder || 1, 10) || 1);
                if (votesFounder) votesFounder.value = String(parseInt(profile.ordinary_votes_founder || 1000, 10) || 1000);
                setUserTypeUI(userTypeSelect ? userTypeSelect.value : 'shareholder');

                const rightsMap = data.share_rights || {};
                if (PREFERENCE_CLASS_ID > 0) {
                    const pref = rightsMap[String(PREFERENCE_CLASS_ID)] || rightsMap[PREFERENCE_CLASS_ID] || null;
                    const sharesCoveredEl = document.getElementById('prefSharesCovered');
                    const form = document.getElementById('preferenceRightsForm');
                    if (sharesCoveredEl) {
                        sharesCoveredEl.value = String(pref && pref.shares_covered ? parseInt(pref.shares_covered, 10) || 0 : 0);
                    }
                    if (form) {
                        form.querySelectorAll('input[type="checkbox"][name^="rights["]').forEach(function (cb) {
                            cb.checked = false;
                        });
                        const r = pref && pref.rights && Array.isArray(pref.rights.rights) ? pref.rights.rights : [];
                        r.forEach(function (code) {
                            const cb = form.querySelector('input[type="checkbox"][name="rights[' + code + ']"]');
                            if (cb) cb.checked = true;
                        });
                    }
                }

                const ord = ORDINARY_CLASS_ID > 0 ? (byClass[String(ORDINARY_CLASS_ID)] || byClass[ORDINARY_CLASS_ID] || null) : null;
                const prefClass = PREFERENCE_CLASS_ID > 0 ? (byClass[String(PREFERENCE_CLASS_ID)] || byClass[PREFERENCE_CLASS_ID] || null) : null;
                const ordUnits = ord ? (parseInt(ord.units_owned || 0, 10) || 0) : 0;
                const prefUnits = prefClass ? (parseInt(prefClass.units_owned || 0, 10) || 0) : 0;
                const ordUps = (ORDINARY_CLASS_ID && UNITS_PER_SHARE_BY_CLASS && UNITS_PER_SHARE_BY_CLASS[String(ORDINARY_CLASS_ID)]) ? parseInt(UNITS_PER_SHARE_BY_CLASS[String(ORDINARY_CLASS_ID)], 10) : 1;
                const prefUps = (PREFERENCE_CLASS_ID && UNITS_PER_SHARE_BY_CLASS && UNITS_PER_SHARE_BY_CLASS[String(PREFERENCE_CLASS_ID)]) ? parseInt(UNITS_PER_SHARE_BY_CLASS[String(PREFERENCE_CLASS_ID)], 10) : 1;
                const ordDenom = Math.max(1, ordUps || 1);
                const prefDenom = Math.max(1, prefUps || 1);
                const fullOrdShares = Math.floor(Math.max(0, ordUnits) / ordDenom);
                const ordRemainder = Math.max(0, ordUnits) % ordDenom;
                const fullPrefShares = Math.floor(Math.max(0, prefUnits) / prefDenom);
                const votes = computeVotes(userTypeSelect ? userTypeSelect.value : 'shareholder', fullOrdShares);

                applySuperVoteRule(fullPrefShares);
                updatePrefSelectedRightsUI();

                if (prefOwnedSummary) {
                    prefOwnedSummary.textContent = 'Owned: ' + fullPrefShares + ' full shares';
                }
                if (prefSharesCoveredEl) {
                    prefSharesCoveredEl.max = String(fullPrefShares);
                    if ((parseInt(prefSharesCoveredEl.value || '0', 10) || 0) > fullPrefShares) {
                        prefSharesCoveredEl.value = String(fullPrefShares);
                    }
                }

                if (selectedUserSummary) {
                    selectedUserSummary.textContent =
                        'Selected: ' + username +
                        ' · Ordinary: ' + fullOrdShares + ' shares + ' + ordRemainder + '/' + ordDenom +
                        ' · Preference full shares: ' + fullPrefShares +
                        ' · Votes: ' + votes.toLocaleString();
                }

                if (userTypeSelect) {
                    const t = String(userTypeSelect.value || 'shareholder').toLowerCase();
                    if (t === 'founder') {
                        let anyChecked = false;
                        if (prefOptions) {
                            prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                                if (cb.checked) anyChecked = true;
                            });
                        }
                        if (!anyChecked) {
                            setPreferenceDefaults('founder', fullPrefShares);
                        }
                    } else if (t === 'shareholder') {
                        let anyChecked = false;
                        if (prefOptions) {
                            prefOptions.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                                if (cb.checked) anyChecked = true;
                            });
                        }
                        if (!anyChecked) {
                            setPreferenceDefaults('shareholder', fullPrefShares);
                        }
                    } else if (t === 'mvi') {
                        setPreferenceDefaults('mvi', 0);
                    }
                }

                lastFullPrefShares = fullPrefShares;
                setLoading(false);
                setNotice('User ' + username + ' loaded and ready to edit.');
            }
            if (recipient) {
                recipient.addEventListener('change', function () {
                    loadUserHoldings(recipient.value);
                });
                if (recipient.value) {
                    loadUserHoldings(recipient.value);
                }
            }

            const rightsStatus = document.getElementById('rightsAutosaveStatus');
            const rightsForms = document.querySelectorAll('form.rightsForm');
            const rightsPending = new Map();
            function setRightsAutosave(text) {
                if (rightsStatus) rightsStatus.textContent = text;
                if (text === 'Autosave: saving...') {
                    setNotice('Saving…');
                }
                if (text === 'Autosave: saved') {
                    setNotice('Saved user rights.');
                }
                if (text === 'Autosave: error') {
                    setNotice('Error saving. Please retry.');
                }
            }
            async function autosaveRights(form) {
                const usernameEl = form.querySelector('input[name="username"]');
                if (usernameEl && (!usernameEl.value || usernameEl.value.trim() === '')) {
                    return;
                }
                const fd = new FormData(form);
                setRightsAutosave('Autosave: saving...');
                const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!res.ok) {
                    setRightsAutosave('Autosave: error');
                    return;
                }
                setRightsAutosave('Autosave: saved');
            }
            rightsForms.forEach(function (form) {
                const key = form.getAttribute('id') || 'rights';
                const handler = function () {
                    if (rightsPending.has(key)) clearTimeout(rightsPending.get(key));
                    rightsPending.set(key, setTimeout(function () {
                        autosaveRights(form);
                    }, 900));
                    setRightsAutosave('Autosave: pending...');
                };
                form.querySelectorAll('input, select').forEach(function (el) {
                    el.addEventListener('input', handler);
                    el.addEventListener('change', handler);
                });
            });

            if (prefOptions) {
                prefOptions.addEventListener('change', function () {
                    updatePrefSelectedRightsUI();
                });
            }
            if (prefSelectedRights && prefOptions) {
                prefSelectedRights.addEventListener('click', function (e) {
                    const t = e.target;
                    if (!t || !(t instanceof HTMLElement)) return;
                    const code = t.getAttribute('data-right-code');
                    if (!code) return;
                    const cb = prefOptions.querySelector('input[type="checkbox"][name="rights[' + code + ']"]');
                    if (!cb) return;
                    cb.checked = false;
                    cb.dispatchEvent(new Event('change', { bubbles: true }));
                    updatePrefSelectedRightsUI();
                });
            }

            if (userTypeSelect) {
                userTypeSelect.addEventListener('change', function () {
                    setUserTypeUI(userTypeSelect.value);
                    if (userTypeSelectPref) userTypeSelectPref.value = userTypeSelect.value;
                    if (userTypeSelect.value === 'founder') {
                        if (votesFounder && (votesFounder.value === '' || parseInt(votesFounder.value, 10) === 0)) {
                            votesFounder.value = '1000';
                        }
                    }
                    if (userTypeSelect.value === 'shareholder') {
                        if (votesShareholder && (votesShareholder.value === '' || parseInt(votesShareholder.value, 10) === 0)) {
                            votesShareholder.value = '1';
                        }
                    }
                    if (userTypeSelect.value === 'mvi') {
                        if (prefForm) {
                            setPreferenceDefaults('mvi', 0);
                        }
                    } else if (userTypeSelect.value === 'founder') {
                        if (prefForm) {
                            setPreferenceDefaults('founder', lastFullPrefShares);
                        }
                    } else if (userTypeSelect.value === 'shareholder') {
                        if (prefForm) {
                            setPreferenceDefaults('shareholder', 0);
                        }
                    }
                });
            }

            if (userTypeSelectPref && userTypeSelect) {
                userTypeSelectPref.addEventListener('change', function () {
                    userTypeSelect.value = userTypeSelectPref.value;
                    userTypeSelect.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }

            const toggleAddRightForm = document.getElementById('toggleAddRightForm');
            if (toggleAddRightForm && addRightForm) {
                toggleAddRightForm.addEventListener('click', function () {
                    addRightForm.style.display = addRightForm.style.display === 'none' ? 'block' : 'none';
                });
            }
        })();
    </script>
</body>
</html>
