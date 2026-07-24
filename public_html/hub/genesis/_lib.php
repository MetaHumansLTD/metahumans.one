<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

function mh_widget_start_session(): void
{
    if (function_exists('startSecureSession')) {
        startSecureSession();
        return;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function mh_widget_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function mh_widget_require_auth(): array
{
    mh_widget_start_session();

    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($u === '') {
        $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/hub/';
        if ($redirect === '' || $redirect[0] !== '/') {
            $redirect = '/hub/';
        }
        mh_widget_json([
            'success' => false,
            'auth' => ['ok' => false],
            'login_url' => '/auth/login.php?redirect=' . rawurlencode($redirect),
        ], 401);
        exit;
    }

    $tenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($tenantId === '') {
        $tenantId = 'user:' . $u;
        $_SESSION['mh_tenant_id'] = $tenantId;
    }
    if (!function_exists('mh_apply_tenant_context') || !mh_apply_tenant_context($tenantId)) {
        mh_widget_json(['success' => false, 'error' => 'tenant_context_failed'], 500);
        exit;
    }

    $personaId = isset($_SESSION['mh_selected_persona']) ? trim((string)$_SESSION['mh_selected_persona']) : '';
    if ($personaId === '') {
        $personaId = isset($_SESSION['mh_auth_persona']) ? trim((string)$_SESSION['mh_auth_persona']) : '';
    }
    if ($personaId === '') {
        $personaId = 'MH-' . $u;
    }

    $metaHumanId = isset($_SESSION['mh_meta_human_id']) ? trim((string)$_SESSION['mh_meta_human_id']) : '';
    if ($metaHumanId === '') {
        $metaHumanId = 'meta:' . strtolower(mh_widget_sanitize_id($personaId));
        $_SESSION['mh_meta_human_id'] = $metaHumanId;
    }

    return [
        'username' => $u,
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'session_id' => session_id(),
    ];
}

function mh_widget_sanitize_id(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function mh_widget_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}

function mh_widget_list_personas(string $username, string $selectedPersona): array
{
    $items = [];
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        if (function_exists('database_getConnectionById') || function_exists('database_getContextAwareConnection')) {
            $pdo = function_exists('database_getConnectionById') ? database_getConnectionById('persona') : database_getContextAwareConnection();
            $pdo->exec("CREATE TABLE IF NOT EXISTS mh_personas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                owner_username VARCHAR(255) NOT NULL,
                user_id VARCHAR(255) NULL,
                tenant_id VARCHAR(255) NULL,
                persona_id VARCHAR(255) NULL,
                meta_human_id VARCHAR(255) NULL,
                persona_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_owner_persona (owner_username, persona_name),
                UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id),
                KEY idx_owner (owner_username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN user_id VARCHAR(255) NULL AFTER owner_username"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN tenant_id VARCHAR(255) NULL AFTER user_id"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN persona_id VARCHAR(255) NULL AFTER tenant_id"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN meta_human_id VARCHAR(255) NULL AFTER persona_id"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id)"); } catch (Throwable) {}
            $stmt = $pdo->prepare("SELECT persona_name, persona_id FROM mh_personas WHERE owner_username = ? ORDER BY persona_name");
            $stmt->execute([$username]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $p = trim((string)($row['persona_name'] ?? ''));
                if ($p === '') {
                    continue;
                }
                $pid = trim((string)($row['persona_id'] ?? ''));
                if ($pid === '') $pid = mh_widget_sanitize_id(strtolower($p));
                $items[] = [
                    'id' => $pid,
                    'name' => $p,
                    'avatar_url' => '/hub/genesis/persona-images.php?persona=' . rawurlencode($pid),
                    'capabilities' => [
                        'realtime' => true,
                        'voice_text' => true,
                        'text_only' => true,
                    ],
                    'selected' => $p === $selectedPersona,
                ];
            }
        }
    } catch (Throwable) {
    }

    if (!$items) {
        $tenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
        if ($tenantId === '') {
            $tenantId = 'user:' . $username;
        }
        $tenantSafe = mh_widget_sanitize_id(strtolower($tenantId));
        $root = '/data/tenants/' . $tenantSafe . '/personas';
        if (is_dir($root)) {
            $entries = scandir($root);
            if (is_array($entries)) {
                foreach ($entries as $e) {
                    if (!is_string($e)) continue;
                    if ($e === '.' || $e === '..') continue;
                    $pid = mh_widget_sanitize_id(strtolower($e));
                    if ($pid === '') continue;
                    $dir = $root . '/' . $e;
                    if (!is_dir($dir)) continue;
                    $name = $e;
                    $manifest = $dir . '/assets/manifest.json';
                    if (is_file($manifest)) {
                        $raw = @file_get_contents($manifest);
                        $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                        if (is_array($j)) {
                            $pn = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
                            if ($pn !== '') $name = $pn;
                        }
                    }
                    $items[] = [
                        'id' => $name,
                        'name' => $name,
                        'avatar_url' => '/hub/genesis/persona-images.php?persona=' . rawurlencode($name),
                        'capabilities' => [
                            'realtime' => true,
                            'voice_text' => true,
                            'text_only' => true,
                        ],
                        'selected' => $name === $selectedPersona,
                    ];
                }
            }
        }
    }

    if (!$items) {
        $items[] = [
            'id' => $selectedPersona,
            'name' => $selectedPersona,
            'avatar_url' => '/hub/genesis/persona-images.php?persona=' . rawurlencode($selectedPersona),
            'capabilities' => [
                'realtime' => true,
                'voice_text' => true,
                'text_only' => true,
            ],
            'selected' => true,
        ];
    }

    return $items;
}

function mh_widget_make_room_id(array $ctx): string
{
    $seed = (string)($ctx['tenant_id'] ?? '') . '|' . (string)($ctx['persona_id'] ?? '') . '|' . microtime(true) . '|' . bin2hex(random_bytes(8));
    $hex = substr(hash('sha256', $seed), 0, 20);
    $room = 'mh_wgt_' . $hex;
    $room = preg_replace('/[^A-Za-z0-9_-]+/', '_', $room);
    $room = substr((string)$room, 0, 64);
    return $room !== '' ? $room : ('mh_wgt_' . bin2hex(random_bytes(8)));
}

function mh_widget_livekit_url(): string
{
    $host = isset($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : '';
    $host = $host !== '' ? $host : 'metahumans.one';
    return 'wss://' . $host . '/rtc';
}
