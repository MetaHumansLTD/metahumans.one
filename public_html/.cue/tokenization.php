<?php
require_once __DIR__ . '/cue.php';

function tokenization_is_exempt_path(string $path): bool {
    if ($path === '') return true;
    $exemptPrefixes = [
        '/v1/',
        '/templates/assets/',
        '/assets/',
        '/auth/',
        '/templates/global-ui/',
        '/gear/settings/',
        '/hub/workbench/api/meet/bot_bridge.php',
    ];
    foreach ($exemptPrefixes as $p) {
        if (strpos($path, $p) === 0) return true;
    }
    return false;
}

function tokenization_expects_json(): bool {
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string)$_SERVER['HTTP_ACCEPT']) : '';
    $xrw = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if ($xrw === 'xmlhttprequest') return true;
    if (strpos($accept, 'application/json') !== false) return true;
    $path = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    if (strpos($path, '/api/') === 0) return true;
    if (strpos($path, '/v1/') === 0) return true;
    return false;
}

function tokenization_get_equity_pdo(): PDO {
    require_once dirname(__DIR__) . '/hub/equity/db.php';
    if (function_exists('getEquityConnectionStrict')) {
        return getEquityConnectionStrict();
    }
    return getEquityConnection();
}

function tokenization_ensure_schema(PDO $pdo): void {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS mh_service_triggers (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        http_method VARCHAR(12) NOT NULL DEFAULT 'POST',
        path_pattern VARCHAR(255) NOT NULL,
        selector_type VARCHAR(32) NOT NULL DEFAULT 'post_action',
        selector_key VARCHAR(64) NOT NULL DEFAULT 'action',
        selector_value VARCHAR(128) NOT NULL,
        service_key VARCHAR(255) NOT NULL,
        units_mode VARCHAR(32) NOT NULL DEFAULT 'fixed',
        units_value INT NOT NULL DEFAULT 1,
        priority INT NOT NULL DEFAULT 100,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_mh_trg_path (path_pattern),
        KEY idx_mh_trg_service (service_key),
        KEY idx_mh_trg_priority (priority),
        UNIQUE KEY uniq_mh_trg (http_method, path_pattern, selector_type, selector_key, selector_value)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

}

function tokenization_match_trigger(PDO $pdo, string $path, string $method, array $post): ?array {
    $path = trim($path);
    $method = strtoupper(trim($method));
    if ($path === '' || $method === '') return null;

    $actionVal = '';
    if (isset($post['action']) && is_string($post['action'])) {
        $actionVal = trim((string)$post['action']);
    }

    if ($actionVal !== '') {
        $stmt = $pdo->prepare("
            SELECT *
            FROM mh_service_triggers
            WHERE enabled = 1
              AND http_method = ?
              AND path_pattern = ?
              AND selector_type = 'post_action'
              AND selector_key = 'action'
              AND selector_value = ?
            ORDER BY priority ASC, id ASC
            LIMIT 1
        ");
        $stmt->execute([$method, $path, $actionVal]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row)) return $row;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM mh_service_triggers
        WHERE enabled = 1
          AND http_method = ?
          AND path_pattern = ?
          AND selector_type = 'path_only'
        ORDER BY priority ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$method, $path]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && !empty($row) ? $row : null;
}

function tokenization_units_from_trigger(array $trigger, array $post): int {
    $mode = isset($trigger['units_mode']) ? strtolower(trim((string)$trigger['units_mode'])) : 'fixed';
    $val = (int)($trigger['units_value'] ?? 1);
    $val = max(1, $val);
    if ($mode === 'fixed') {
        return $val;
    }
    if ($mode === 'post_int') {
        $k = isset($trigger['selector_key']) ? trim((string)$trigger['selector_key']) : '';
        if ($k !== '' && isset($post[$k])) {
            $n = (int)$post[$k];
            return max(1, $n);
        }
        return $val;
    }
    return $val;
}

function tokenization_enforce_request(): void {
    if (PHP_SAPI === 'cli') return;
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) !== 'POST') return;

    $path = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    if ($path === '' || tokenization_is_exempt_path($path)) return;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (function_exists('security_startSecureSession')) {
            security_startSecureSession();
        } elseif (function_exists('startSecureSession')) {
            startSecureSession();
        } else {
            @session_start();
        }
    }

    $username = isset($_SESSION['mh_auth_user']) && is_string($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($username === '') return;

    try {
        require_once dirname(__DIR__) . '/auth/auth_functions.php';
        if (!function_exists('mh_charge_service_tokens')) return;
        $pdo = tokenization_get_equity_pdo();
        tokenization_ensure_schema($pdo);
        $trigger = tokenization_match_trigger($pdo, $path, (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'), $_POST);
        if (!$trigger) return;

        $serviceKey = isset($trigger['service_key']) ? trim((string)$trigger['service_key']) : '';
        if ($serviceKey === '') return;

        $units = tokenization_units_from_trigger($trigger, $_POST);
        $meta = [
            'path' => $path,
            'post_action' => isset($_POST['action']) ? (string)$_POST['action'] : '',
            'trigger_id' => (int)($trigger['id'] ?? 0),
        ];
        $charge = mh_charge_service_tokens($username, $serviceKey, $units, $meta, 1);
        if (isset($charge['success']) && $charge['success'] === true) {
            return;
        }

        $bal = isset($charge['tokens']) ? (int)$charge['tokens'] : 0;
        $debited = isset($charge['debited']) ? (int)$charge['debited'] : 0;
        $msg = 'Insufficient MTK to perform this action. Balance: ' . number_format($bal) . ' MTK.';
        if ($debited > 0) {
            $msg = 'Token charge failed. Please retry.';
        }

        $topupUrl = '/hub/genesis/tokenization.php?next=' . rawurlencode($path);
        $_SESSION['mh_after_topup_next'] = $path;

        if (tokenization_expects_json()) {
            http_response_code(402);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'insufficient_tokens', 'message' => $msg, 'tokens' => $bal, 'redirect' => $topupUrl], JSON_UNESCAPED_SLASHES);
            exit;
        }

        $_SESSION['mh_token_charge_flash'] = $msg;
        header('Location: ' . $topupUrl);
        exit;
    } catch (Throwable $e) {
        return;
    }
}
