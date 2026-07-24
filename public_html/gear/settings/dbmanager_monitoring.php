<?php
declare(strict_types=1);

function dbmanager_monitoring_log_path(): string {
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    $dir = $base . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/dbmanager_audit.jsonl';
}

function dbmanager_monitoring_write(array $event): void {
    $path = dbmanager_monitoring_log_path();
    $line = json_encode($event, JSON_UNESCAPED_SLASHES);
    if (!is_string($line) || $line === '') return;
    @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
}

function dbmanager_monitoring_init(bool $isAjax): void {
    $start = microtime(true);
    register_shutdown_function(function () use ($start, $isAjax) {
        $err = error_get_last();
        $username = '';
        if (session_status() === PHP_SESSION_ACTIVE) {
            $username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
        }
        $action = '';
        if (isset($_POST['action']) && is_string($_POST['action'])) {
            $action = trim((string)$_POST['action']);
        }
        $configId = '';
        foreach (['configId', 'config_id', 'db_config_id'] as $k) {
            if (isset($_POST[$k]) && is_string($_POST[$k])) {
                $configId = trim((string)$_POST[$k]);
                break;
            }
        }
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $code = function_exists('http_response_code') ? (int)http_response_code() : 0;

        $event = [
            'ts' => time(),
            't_ms' => (int)round((microtime(true) - $start) * 1000),
            'ajax' => $isAjax,
            'method' => $method,
            'uri' => $uri,
            'user' => $username,
            'action' => $action,
            'config_id' => $configId,
            'status' => $code,
            'ip' => $ip,
            'ua' => $ua !== '' ? substr($ua, 0, 240) : '',
            'mem' => [
                'peak' => function_exists('memory_get_peak_usage') ? (int)memory_get_peak_usage(true) : null,
                'cur' => function_exists('memory_get_usage') ? (int)memory_get_usage(true) : null,
            ],
        ];
        if (is_array($err) && isset($err['type'], $err['message'])) {
            $t = (int)$err['type'];
            $fatalTypes = [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
                E_USER_ERROR,
                E_RECOVERABLE_ERROR,
            ];
            if (in_array($t, $fatalTypes, true)) {
                $event['fatal'] = [
                    'type' => $t,
                    'message' => (string)$err['message'],
                    'file' => (string)($err['file'] ?? ''),
                    'line' => (int)($err['line'] ?? 0),
                ];
            }
        }
        dbmanager_monitoring_write($event);
    });
}

if (php_sapi_name() !== 'cli') {
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $script !== '' ? (realpath($script) ?: $script) : '';
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $phpSelf = (string)($_SERVER['PHP_SELF'] ?? '');
    $isDirect = false;
    if ($real !== '' && (realpath(__FILE__) ?: __FILE__) === $real) {
        $isDirect = true;
    } elseif ($uri !== '' && preg_match('#/gear/settings/dbmanager_monitoring\\.php(?:\\?|$)#', $uri)) {
        $isDirect = true;
    } elseif ($phpSelf !== '' && preg_match('#/gear/settings/dbmanager_monitoring\\.php$#', $phpSelf)) {
        $isDirect = true;
    }
    if ($isDirect) {
        header('Content-Type: text/html; charset=UTF-8');
        $target = '/gear/settings/dbmanager_monitor.php';
        echo '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES) . '"><title>DB Manager Monitoring</title></head><body>';
        echo 'This file is a library. Open <a href="' . htmlspecialchars($target, ENT_QUOTES) . '">DB Manager Monitor</a>.';
        echo '</body></html>';
        exit;
    }
}
