<?php

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/kripz_gate.php';

$pageTitle = 'Enterprise Monitoring Dashboard';
$refreshInterval = 0;
if (isset($_GET['refresh'])) {
    $refreshInterval = (int)$_GET['refresh'];
    if ($refreshInterval < 0) $refreshInterval = 0;
    if ($refreshInterval > 3600) $refreshInterval = 3600;
}

define('MONITOR_ALERT_LOG', '/data/logs/enterprise-monitor/alerts.log');
define('ENTERPRISE_MONITOR_LOG_ROOT', '/data/logs');

function enterprise_monitor_log_state_for_path(string $path): string {
    $size = @filesize($path);
    $size = is_int($size) ? $size : 0;
    return $size > 0 ? 'has_content' : 'empty';
}

function enterprise_monitor_log_severity_for_path(string $path): string {
    if (enterprise_monitor_log_state_for_path($path) === 'empty') return 'info';
    $b = strtolower(basename($path));
    if (str_contains($b, 'cue-error') || str_contains($b, 'error.log')) return 'error';
    if (str_contains($b, 'alert')) return 'warning';
    if (str_contains($b, 'audit')) return 'info';
    if (str_contains($b, 'status') || str_contains($b, 'state') || str_contains($b, 'metrics')) return 'info';
    return 'info';
}

function enterprise_monitor_indicator_for_severity(string $severity): string {
    $s = strtolower(trim($severity));
    if ($s === 'error' || $s === 'critical') return 'error';
    if ($s === 'warning' || $s === 'warn') return 'warning';
    return 'success';
}

function enterprise_monitor_is_allowed_log_path(string $path): bool {
    $path = trim($path);
    if ($path === '') return false;
    $rp = realpath($path);
    if (!is_string($rp) || $rp === '') return false;
    $root = realpath(ENTERPRISE_MONITOR_LOG_ROOT);
    if (!is_string($root) || $root === '') return false;
    if (!str_starts_with($rp, $root . DIRECTORY_SEPARATOR) && $rp !== $root) return false;
    if (!is_file($rp)) return false;
    return true;
}

function enterprise_monitor_clear_log_file(string $path): bool {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return (@file_put_contents($path, '', LOCK_EX) !== false);
}

function enterprise_monitor_tail_lines(string $path, int $maxLines = 20, int $maxBytes = 262144): array {
    $maxLines = max(1, $maxLines);
    $maxBytes = max(1024, $maxBytes);
    $fp = @fopen($path, 'rb');
    if ($fp === false) return [];

    $size = @filesize($path);
    $size = is_int($size) ? $size : 0;
    $pos = $size;
    $buffer = '';
    while ($pos > 0 && strlen($buffer) < $maxBytes) {
        $read = min(8192, $pos);
        $pos -= $read;
        if (@fseek($fp, $pos) !== 0) break;
        $chunk = @fread($fp, $read);
        if (!is_string($chunk) || $chunk === '') break;
        $buffer = $chunk . $buffer;
        if (substr_count($buffer, "\n") > ($maxLines + 5)) break;
    }
    @fclose($fp);

    $lines = preg_split("/\r\n|\n|\r/", $buffer);
    if (!is_array($lines)) return [];
    $lines = array_values(array_filter($lines, static fn($l) => trim((string)$l) !== ''));
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }
    return $lines;
}

function enterprise_monitor_log_preview(string $path): string {
    $b = strtolower(basename($path));
    if (str_ends_with($b, '.json')) {
        $raw = @file_get_contents($path);
        $raw = is_string($raw) ? $raw : '';
        if ($raw === '') return '';
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $pretty = is_string($pretty) ? $pretty : $raw;
            return mb_substr($pretty, 0, 4000);
        }
        return mb_substr($raw, 0, 4000);
    }

    $lines = enterprise_monitor_tail_lines($path, 20);
    return implode("\n", $lines);
}

function enterprise_monitor_list_log_files(): array {
    $out = [];
    $root = ENTERPRISE_MONITOR_LOG_ROOT;
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (!$f instanceof SplFileInfo) continue;
            if (!$f->isFile()) continue;
            $p = $f->getPathname();
            if (!is_string($p) || $p === '') continue;
            if (basename($p) === '.write_test') continue;
            $out[] = $p;
        }
    }
    sort($out);
    return $out;
}

$k8sControlPlanes = [
    'rke-cp-1' => ['host' => 'rke-cp-1.superhumans.one'],
    'rke-cp-2' => ['host' => 'rke-cp-2.superhumans.one'],
];

mh_kripz_require('enterprise_monitor', false);

function enterprise_monitor_csrf(): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $k = 'mh_enterprise_monitor_csrf';
    $t = isset($_SESSION[$k]) ? (string)$_SESSION[$k] : '';
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        $_SESSION[$k] = $t;
    }
    return $t;
}

function enterprise_monitor_require_csrf(): bool {
    $t = enterprise_monitor_csrf();
    $p = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    return ($p !== '' && hash_equals($t, $p));
}

function enterprise_monitor_is_ajax_request(): bool {
    $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower(trim((string)$_SERVER['HTTP_X_REQUESTED_WITH'])) : '';
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }
    $accept = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string)$_SERVER['HTTP_ACCEPT']) : '';
    return str_contains($accept, 'application/json');
}

function enterprise_monitor_rotate_alert_log(string $reason = ''): array {
    $dir = dirname(MONITOR_ALERT_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file(MONITOR_ALERT_LOG)) {
        $ok = @file_put_contents(MONITOR_ALERT_LOG, '', LOCK_EX) !== false;
        return ['ok' => $ok, 'rotated' => false, 'reason' => $reason, 'path' => MONITOR_ALERT_LOG];
    }
    $sz = @filesize(MONITOR_ALERT_LOG);
    $sz = is_int($sz) ? $sz : 0;
    if ($sz <= 0) {
        return ['ok' => true, 'rotated' => false, 'reason' => $reason, 'path' => MONITOR_ALERT_LOG, 'size' => $sz];
    }
    $ts = gmdate('Ymd\\THis\\Z');
    $archive = $dir . '/alerts.' . $ts . '.log';
    $ok = @rename(MONITOR_ALERT_LOG, $archive);
    if ($ok) {
        @file_put_contents(MONITOR_ALERT_LOG, '', LOCK_EX);
    }
    return ['ok' => $ok, 'rotated' => $ok, 'archive' => $archive, 'reason' => $reason, 'path' => MONITOR_ALERT_LOG, 'size' => $sz];
}

function enterprise_monitor_ssh_exec(string $host, string $sshKey, string $remoteCommand, int $timeoutSeconds = 6, string $user = 'mhadmin'): array {
    $disabled = (string)ini_get('disable_functions');
    if ($disabled !== '') {
        $parts = array_filter(array_map('trim', explode(',', $disabled)), static fn ($v) => $v !== '');
        if (in_array('exec', $parts, true)) {
            return ['ok' => false, 'exit' => 127, 'output' => ['exec_disabled']];
        }
    }
    if (!function_exists('exec')) {
        return ['ok' => false, 'exit' => 127, 'output' => ['exec_disabled']];
    }
    if (!is_readable($sshKey)) {
        return ['ok' => false, 'exit' => 255, 'output' => ['ssh_key_not_readable']];
    }
    $knownHostsFile = '/home/onemeta/.ssh/known_hosts_enterprise_monitor';
    $knownHostsDir = dirname($knownHostsFile);
    if (!is_dir($knownHostsDir)) {
        @mkdir($knownHostsDir, 0700, true);
    }
    if (!is_file($knownHostsFile)) {
        @file_put_contents($knownHostsFile, '', LOCK_EX);
        @chmod($knownHostsFile, 0600);
    }
    $cmd = sprintf(
        'ssh -i %s -o IdentitiesOnly=yes -o UserKnownHostsFile=%s -o GlobalKnownHostsFile=/dev/null -o StrictHostKeyChecking=accept-new -o ConnectTimeout=%d -o BatchMode=yes %s %s 2>&1',
        escapeshellarg($sshKey),
        escapeshellarg($knownHostsFile),
        max(1, $timeoutSeconds),
        escapeshellarg($user . '@' . $host),
        escapeshellarg($remoteCommand)
    );
    $output = [];
    $exit = 0;
    exec($cmd, $output, $exit);
    return ['ok' => ($exit === 0), 'exit' => $exit, 'output' => $output];
}

function enterprise_monitor_ssh_user_for_host(string $host): string {
    $h = strtolower(trim($host));
    if ($h === 'metahumans.one') return 'root';
    return 'mhadmin';
}

function enterprise_monitor_ssh_keys_for_host(string $host): array {
    $host = strtolower(trim($host));
    $keys = [];
    $k1 = '/home/onemeta/.ssh/id_ed25519_nopass';
    $k2 = '/home/onemeta/.ssh/superhumans_one_ed25519';
    if ($host === 'superhumans.one') {
        $keys[] = $k2;
        $keys[] = $k1;
    } else {
        $keys[] = $k1;
        $keys[] = $k2;
    }
    $out = [];
    foreach ($keys as $k) {
        if (is_string($k) && $k !== '' && is_readable($k)) $out[] = $k;
    }
    return array_values(array_unique($out));
}

function enterprise_monitor_ssh_exec_any(string $host, array $sshKeys, string $remoteCommand, int $timeoutSeconds = 6, ?string $user = null): array {
    $sshKeys = array_values(array_filter($sshKeys, static fn($p) => is_string($p) && $p !== '' && is_readable($p)));
    if (empty($sshKeys)) {
        return ['ok' => false, 'exit' => 255, 'output' => ['ssh_key_not_readable']];
    }
    $userToUse = is_string($user) && $user !== '' ? $user : enterprise_monitor_ssh_user_for_host($host);
    $attempts = [];
    foreach ($sshKeys as $k) {
        $res = enterprise_monitor_ssh_exec($host, $k, $remoteCommand, $timeoutSeconds, $userToUse);
        $attempts[] = ['key' => $k, 'ok' => $res['ok'] ?? false, 'exit' => $res['exit'] ?? null];
        if (($res['ok'] ?? false) === true) {
            $res['attempts'] = $attempts;
            return $res;
        }
    }
    $last = $res ?? ['ok' => false, 'exit' => 255, 'output' => ['ssh_failed']];
    $last['attempts'] = $attempts;
    return $last;
}

function checkPersonaplexAssets(string $host, string $sshKey): array {
    $bases = ['/mnt/personaplex', '/opt/personaplex'];
    $check = [];
    foreach ($bases as $base) {
        $check[] = [
            'base' => $base,
            'model' => $base . '/model.safetensors',
            'tokenizer' => $base . '/tokenizer_spm_32k_3.model',
            'mimi' => $base . '/tokenizer-e351c8d8-checkpoint125.safetensors',
            'voices_dir' => $base . '/voices/voices',
        ];
    }
    $remoteScript =
        'set -euo pipefail; ' .
        'for base in /mnt/personaplex /opt/personaplex; do ' .
        'm="$base/model.safetensors"; ' .
        't="$base/tokenizer_spm_32k_3.model"; ' .
        'mi="$base/tokenizer-e351c8d8-checkpoint125.safetensors"; ' .
        'v="$base/voices/voices"; ' .
        'if [ -f "$m" ] && [ -f "$t" ] && [ -f "$mi" ] && [ -d "$v" ]; then ' .
        'ls -1 "$v" >/dev/null; ' .
        'echo "OK:$base"; ' .
        'exit 0; ' .
        'fi; ' .
        'done; ' .
        'echo "MISSING"; ' .
        'exit 2';
    $remote = 'bash -c ' . escapeshellarg($remoteScript);
    $res = enterprise_monitor_ssh_exec($host, $sshKey, $remote, 8);
    $out = trim(implode("\n", $res['output']));
    $ok = $res['ok'] && strpos($out, 'OK:') !== false;
    $usedBase = null;
    if ($ok) {
        $pos = strpos($out, 'OK:');
        $usedBase = $pos !== false ? trim(substr($out, $pos + 3)) : null;
    }
    return [
        'ok' => $ok,
        'candidates' => $check,
        'used_base' => $usedBase,
        'ssh' => ['ok' => $res['ok'], 'exit' => $res['exit']],
        'detail' => $out,
    ];
}

$enterpriseMonitorFlash = null;
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST' && isset($_POST['action'])) {
    $ajaxRequest = enterprise_monitor_is_ajax_request();
    if (!enterprise_monitor_require_csrf()) {
        http_response_code(400);
        $enterpriseMonitorFlash = ['type' => 'error', 'message' => 'Invalid CSRF token'];
    } else {
        $action = (string)$_POST['action'];
        if ($action === 'clear_alerts') {
            $rot = enterprise_monitor_rotate_alert_log('manual_clear');
            $enterpriseMonitorFlash = [
                'type' => ($rot['ok'] ?? false) ? 'success' : 'error',
                'message' => ($rot['ok'] ?? false) ? 'Alert log cleared' : 'Failed to clear alert log',
            ];
        } elseif ($action === 'clear_log') {
            $p = isset($_POST['log']) ? (string)$_POST['log'] : '';
            if ($p === '' || !enterprise_monitor_is_allowed_log_path($p)) {
                $enterpriseMonitorFlash = ['type' => 'error', 'message' => 'Invalid log path'];
            } else {
                $ok = enterprise_monitor_clear_log_file($p);
                $enterpriseMonitorFlash = [
                    'type' => $ok ? 'success' : 'error',
                    'message' => $ok ? ('Log cleared: ' . basename($p)) : ('Failed to clear log: ' . basename($p)),
                ];
            }
        }
    }
    if ($ajaxRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        $payload = [
            'ok' => is_array($enterpriseMonitorFlash) && (($enterpriseMonitorFlash['type'] ?? '') === 'success'),
            'flash' => $enterpriseMonitorFlash,
            'action' => isset($_POST['action']) ? (string)$_POST['action'] : '',
        ];
        if (isset($_POST['log'])) {
            $payload['log'] = (string)$_POST['log'];
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function enterprise_monitor_fast_mode(): bool {
    return true;
}

function checkTcpPort(string $host, int $port, float $timeoutSeconds = 0.35): array {
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
    if ($fp) {
        fclose($fp);
        return [
            'open' => true,
            'latency_ms' => (int)round((microtime(true) - $start) * 1000),
            'error' => null,
        ];
    }
    return [
        'open' => false,
        'latency_ms' => null,
        'error' => $errstr !== '' ? $errstr : ($errno ? (string)$errno : null),
    ];
}

function checkHttpJson(string $url, float $timeoutSeconds = 1.2): array {
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'http_code' => null, 'data' => null, 'error' => 'curl_init_failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int)max(1, floor($timeoutSeconds)),
        CURLOPT_TIMEOUT => (int)max(1, ceil($timeoutSeconds)),
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'http_code' => $httpCode ?: null, 'data' => null, 'error' => $err !== '' ? $err : 'curl_exec_failed'];
    }
    $data = json_decode($body, true);
    return ['ok' => ($httpCode >= 200 && $httpCode < 300 && is_array($data)), 'http_code' => $httpCode, 'data' => $data, 'error' => null];
}

function checkHttpText(string $url, float $timeoutSeconds = 1.2): array {
    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'http_code' => null, 'body' => null, 'error' => 'curl_init_failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => (int)max(1, floor($timeoutSeconds)),
        CURLOPT_TIMEOUT => (int)max(1, ceil($timeoutSeconds)),
        CURLOPT_FAILONERROR => false,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'http_code' => $httpCode ?: null, 'body' => null, 'error' => $err !== '' ? $err : 'curl_exec_failed'];
    }
    $bodyStr = is_string($body) ? $body : '';
    if (strlen($bodyStr) > 200000) {
        $bodyStr = substr($bodyStr, 0, 200000);
    }
    return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http_code' => $httpCode, 'body' => $bodyStr, 'error' => null];
}

function enterprise_monitor_check_netdata_target(string $name, string $host, ?string $sshKey = null): array {
    $isLocal = in_array($host, ['127.0.0.1', 'localhost'], true);
    $doDirect = $isLocal || !enterprise_monitor_fast_mode();

    $tcp = ['open' => null, 'latency_ms' => null, 'error' => null];
    $http = ['ok' => null, 'http_code' => null, 'data' => null, 'error' => null];
    $directOk = false;

    if ($doDirect) {
        $http = checkHttpJson('http://' . $host . ':19999/api/v1/info', 1.2);
        $tcp = checkTcpPort($host, 19999, 0.35);
        $directOk = (($tcp['open'] ?? false) === true) && (($http['ok'] ?? false) === true);
    }

    $sshLocal = null;
    $sshOk = false;
    $version = null;
    if (($http['ok'] ?? false) && is_array($http['data'] ?? null)) {
        $version = $http['data']['version'] ?? $http['data']['netdata_version'] ?? null;
        if (is_string($version)) $version = trim($version);
    }

    if (!$directOk && !$isLocal) {
        $remoteScript =
            'set -euo pipefail; ' .
            'if command -v curl >/dev/null 2>&1; then ' .
            'curl -sS --max-time 2 http://127.0.0.1:19999/api/v1/info; ' .
            'else echo "NO_CURL"; exit 4; fi';
        $remote = 'bash -c ' . escapeshellarg($remoteScript);
        $keys = [];
        if (is_string($sshKey) && $sshKey !== '' && is_readable($sshKey)) {
            $keys[] = $sshKey;
        } else {
            $keys = enterprise_monitor_ssh_keys_for_host($host);
        }
        $sshRes = enterprise_monitor_ssh_exec_any($host, $keys, $remote, 6);
        $body = trim(implode("\n", $sshRes['output'] ?? []));
        $decoded = $body !== '' ? json_decode($body, true) : null;
        $sshOk = ($sshRes['ok'] ?? false) === true && is_array($decoded);
        $sshLocal = [
            'ok' => $sshOk,
            'exit' => $sshRes['exit'] ?? null,
            'error' => $sshOk ? null : ($body !== '' ? substr($body, 0, 300) : 'ssh_or_json_failed'),
        ];
        if ($sshOk) {
            $version = $decoded['version'] ?? $decoded['netdata_version'] ?? $version;
            if (is_string($version)) $version = trim($version);
        }
    }

    return [
        'name' => $name,
        'host' => $host,
        'tcp' => $tcp,
        'http' => $http,
        'direct_ok' => $directOk,
        'ssh_local' => $sshLocal,
        'ssh_ok' => $sshOk,
        'version' => $version,
        'ok' => ($directOk || $sshOk),
    ];
}

function enterprise_monitor_check_netdata_endpoints(array $targets, ?string $sshKey = null): array {
    $rows = [];
    $okAny = false;
    foreach ($targets as $t) {
        if (!is_array($t)) continue;
        $name = isset($t['name']) ? trim((string)$t['name']) : '';
        $host = isset($t['host']) ? trim((string)$t['host']) : '';
        if ($host === '') continue;
        if ($name === '') $name = $host;
        $r = enterprise_monitor_check_netdata_target($name, $host, $sshKey);
        $rows[] = $r;
        if (($r['ok'] ?? false) === true) $okAny = true;
    }
    return ['ok' => $okAny, 'targets' => $rows];
}

function enterprise_monitor_check_opencost_http_target(string $name, string $host): array {
    $http = checkHttpText('http://' . $host . ':9003/metrics', 1.2);
    $tcp = [
        'open' => ($http['ok'] ?? false) === true,
        'latency_ms' => null,
        'error' => null,
    ];
    $hasMarker = false;
    $marker = null;
    if (($http['ok'] ?? false) && is_string($http['body'] ?? null)) {
        $b = (string)$http['body'];
        $hasMarker = (stripos($b, 'opencost') !== false) || (stripos($b, 'kubecost') !== false);
        if ($hasMarker) $marker = 'metrics_contains_opencost';
    }
    return [
        'name' => $name,
        'host' => $host,
        'tcp' => $tcp,
        'http' => $http,
        'marker' => $marker,
        'ok' => (($tcp['open'] ?? false) === true) && (($http['ok'] ?? false) === true) && $hasMarker,
    ];
}

function enterprise_monitor_check_opencost_http_endpoints(array $targets): array {
    $rows = [];
    $okAny = false;
    foreach ($targets as $t) {
        if (!is_array($t)) continue;
        $name = isset($t['name']) ? trim((string)$t['name']) : '';
        $host = isset($t['host']) ? trim((string)$t['host']) : '';
        if ($host === '') continue;
        if ($name === '') $name = $host;
        $r = enterprise_monitor_check_opencost_http_target($name, $host);
        $rows[] = $r;
        if (($r['ok'] ?? false) === true) $okAny = true;
    }
    return ['ok' => $okAny, 'targets' => $rows];
}

function enterprise_monitor_check_opencost_kubectl_on_node(string $host, string $sshKey): array {
    $script =
        'set -euo pipefail; ' .
        'kc=""; ' .
        'if [ -f /etc/rancher/rke2/rke2.yaml ]; then kc="/etc/rancher/rke2/rke2.yaml"; fi; ' .
        'k=""; ' .
        'trycmd(){ sh -c "$1" >/dev/null 2>&1; }; ' .
        'if [ "$k" = "" ] && [ -x /var/lib/rancher/rke2/bin/kubectl ]; then ' .
        'if command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1 && trycmd "sudo -n env KUBECONFIG=$kc /var/lib/rancher/rke2/bin/kubectl version --request-timeout=4s"; then ' .
        'k="sudo -n env KUBECONFIG=$kc /var/lib/rancher/rke2/bin/kubectl"; ' .
        'fi; ' .
        'fi; ' .
        'if command -v rke2 >/dev/null 2>&1; then ' .
        'if trycmd "rke2 kubectl version --request-timeout=4s"; then k="rke2 kubectl"; ' .
        'elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1 && trycmd "sudo -n rke2 kubectl version --request-timeout=4s"; then k="sudo -n rke2 kubectl"; fi; ' .
        'fi; ' .
        'if [ "$k" = "" ] && command -v kubectl >/dev/null 2>&1; then ' .
        'if trycmd "kubectl version --request-timeout=4s"; then k="kubectl"; ' .
        'elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1 && trycmd "sudo -n kubectl version --request-timeout=4s"; then k="sudo -n kubectl"; fi; ' .
        'fi; ' .
        'if [ "$k" = "" ]; then echo "NO_KUBECTL"; exit 3; fi; ' .
        'pods=$($k get pods -A --no-headers 2>/dev/null | grep -i opencost || true); ' .
        'svcs=$($k get svc -A --no-headers 2>/dev/null | grep -i opencost || true); ' .
        'eps=$($k get endpoints -A --no-headers 2>/dev/null | grep -i opencost || true); ' .
        'ready_ok=0; ' .
        'if [ -n "$pods" ]; then ready_ok=$(echo "$pods" | awk \'{split($3,a,"/"); if(a[1]==a[2] && $4=="Running"){print 1; exit}}\' || true); fi; ' .
        'ep_ok=0; ' .
        'if [ -n "$eps" ]; then ep_ok=$(echo "$eps" | awk \'{ if($3 != "" && $3 != "<none>"){print 1; exit}}\' || true); fi; ' .
        'if [ -n "$pods" ]; then echo "PODS_FOUND"; echo "$pods" | head -n 12; fi; ' .
        'if [ "$ready_ok" = "1" ]; then echo "PODS_READY"; fi; ' .
        'if [ -n "$svcs" ]; then echo "SVCS_FOUND"; echo "$svcs" | head -n 12; fi; ' .
        'if [ -n "$eps" ]; then echo "ENDPOINTS_FOUND"; echo "$eps" | head -n 12; fi; ' .
        'if [ "$ep_ok" = "1" ]; then echo "ENDPOINTS_READY"; fi; ' .
        'if [ -z "$pods" ] && [ -z "$svcs" ]; then echo "NOT_FOUND"; exit 2; fi; ' .
        'if [ "$ready_ok" = "1" ] && [ "$ep_ok" = "1" ]; then exit 0; fi; ' .
        'echo "NOT_READY"; exit 1';
    $remote = 'bash -c ' . escapeshellarg($script);
    $keys = is_string($sshKey) && $sshKey !== '' ? [$sshKey] : enterprise_monitor_ssh_keys_for_host($host);
    $res = enterprise_monitor_ssh_exec_any($host, $keys, $remote, 6);
    $out = implode("\n", $res['output'] ?? []);
    $podsOk = stripos($out, 'PODS_FOUND') !== false;
    $svcsOk = stripos($out, 'SVCS_FOUND') !== false;
    $podsReady = stripos($out, 'PODS_READY') !== false;
    $epsFound = stripos($out, 'ENDPOINTS_FOUND') !== false;
    $epsReady = stripos($out, 'ENDPOINTS_READY') !== false;
    $ok = ($res['ok'] ?? false) === true && $podsReady && $epsReady;
    return [
        'host' => $host,
        'ssh' => ['ok' => $res['ok'] ?? false, 'exit' => $res['exit'] ?? null],
        'pods_found' => $podsOk,
        'svcs_found' => $svcsOk,
        'pods_ready' => $podsReady,
        'endpoints_found' => $epsFound,
        'endpoints_ready' => $epsReady,
        'ok' => $ok,
        'detail' => trim($out),
    ];
}

function enterprise_monitor_check_opencost_kubectl(array $nodes, string $sshKey): array {
    $rows = [];
    $okAny = false;
    foreach ($nodes as $nodeName => $cfg) {
        $host = is_array($cfg) ? (string)($cfg['host'] ?? '') : '';
        $host = trim($host);
        if ($host === '') continue;
        $r = enterprise_monitor_check_opencost_kubectl_on_node($host, $sshKey);
        $r['node'] = (string)$nodeName;
        $rows[] = $r;
        if (($r['ok'] ?? false) === true) $okAny = true;
    }
    return ['ok' => $okAny, 'nodes' => $rows, 'ssh_key' => $sshKey];
}

function checkK8sControlPlane(string $host): array {
    $ssh = checkTcpPort($host, 22, 1.2);
    $api = checkTcpPort($host, 6443, 1.2);
    $supervisor = checkTcpPort($host, 9345, 1.2);
    return [
        'reachable' => ($ssh['open'] || $api['open'] || $supervisor['open']),
        'ssh_port_open' => $ssh['open'],
        'https_port_open' => $api['open'],
        'supervisor_port_open' => $supervisor['open'],
    ];
}

// Run checks
$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => [],
];

// Ollama checks (HTTP)
$results['checks']['ollama_superhumans'] = checkHttpJson('http://superhumans.one:11434/api/version', 2.5);

// LiveKit checks (meet.metahumans.one proxy + usa.metahumans.one backend)
$results['checks']['livekit_metahumans'] = [
    'running' => false,
    'ports' => [
        'meet:443' => checkTcpPort('meet.metahumans.one', 443, 1.2),
        'usa:7880' => checkTcpPort('usa.metahumans.one', 7880, 1.2),
        'usa:7881' => checkTcpPort('usa.metahumans.one', 7881, 1.2),
    ],
];
$results['checks']['livekit_metahumans']['running'] = (
    ($results['checks']['livekit_metahumans']['ports']['meet:443']['open'] ?? false)
    && (
        ($results['checks']['livekit_metahumans']['ports']['usa:7880']['open'] ?? false)
        || ($results['checks']['livekit_metahumans']['ports']['usa:7881']['open'] ?? false)
    )
);

// PersonaPlex checks (TCP + asset presence)
$results['checks']['personaplex_port'] = checkTcpPort('meta.superhumans.one', 8998, 1.2);
$results['checks']['personaplex_assets'] = checkPersonaplexAssets('meta.superhumans.one', '/home/onemeta/.ssh/superhumans_one_ed25519');

// PlugNMeet checks (public metahumans.one UI)
$results['checks']['plugnmeet_metahumans'] = checkHttpText('https://metahumans.one/plugnmeet/', 1.5);

// SSH port checks (TCP)
$results['checks']['ssh_superhumans'] = checkTcpPort('superhumans.one', 22, 1.2);
$results['checks']['ssh_superbrains'] = checkTcpPort('superbrains.one', 22, 1.2);
$results['checks']['ssh_metahumans'] = checkTcpPort('metahumans.one', 22, 1.2);
$results['checks']['ssh_api_superhumans'] = checkTcpPort('api.superhumans.one', 22, 1.2);
$results['checks']['ssh_ingress_superhumans'] = checkTcpPort('ingress.superhumans.one', 22, 1.2);

// Check K8s control plane nodes
foreach ($k8sControlPlanes as $nodeName => $nodeConfig) {
    $results['checks']["k8s_cp_{$nodeName}"] = checkK8sControlPlane($nodeConfig['host']);
}

// Database port checks (local host)
$results['checks']['db_3306'] = checkTcpPort('127.0.0.1', 3306, 1.2);
$results['checks']['db_3307'] = checkTcpPort('127.0.0.1', 3307, 1.2);

$results['checks']['netdata_endpoints'] = enterprise_monitor_check_netdata_endpoints([
    ['name' => 'local', 'host' => '127.0.0.1'],
    ['name' => 'metahumans.one', 'host' => 'metahumans.one'],
    ['name' => 'superhumans.one', 'host' => 'superhumans.one'],
    ['name' => 'rke-cp-1', 'host' => 'rke-cp-1.superhumans.one'],
    ['name' => 'rke-cp-2', 'host' => 'rke-cp-2.superhumans.one'],
]);
$results['checks']['opencost_kubectl'] = enterprise_monitor_check_opencost_kubectl($k8sControlPlanes, '');
if (($results['checks']['opencost_kubectl']['ok'] ?? false) === true) {
    $results['checks']['opencost_http_endpoints'] = ['ok' => true, 'targets' => [], 'skipped' => true];
} else {
    $results['checks']['opencost_http_endpoints'] = enterprise_monitor_check_opencost_http_endpoints([
        ['name' => 'local', 'host' => '127.0.0.1'],
        ['name' => 'metahumans.one', 'host' => 'metahumans.one'],
        ['name' => 'superhumans.one', 'host' => 'superhumans.one'],
        ['name' => 'superbrains.one', 'host' => 'superbrains.one'],
        ['name' => 'rke-cp-1', 'host' => 'rke-cp-1.superhumans.one'],
        ['name' => 'rke-cp-2', 'host' => 'rke-cp-2.superhumans.one'],
    ]);
}

function enterprise_monitor_format_bytes(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    $kb = $bytes / 1024;
    if ($kb < 1024) return number_format($kb, 1) . ' KB';
    $mb = $kb / 1024;
    if ($mb < 1024) return number_format($mb, 1) . ' MB';
    $gb = $mb / 1024;
    if ($gb < 1024) return number_format($gb, 1) . ' GB';
    $tb = $gb / 1024;
    return number_format($tb, 2) . ' TB';
}

function enterprise_monitor_get_mountpoint(string $path): ?string {
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($mounts)) return null;
    foreach ($mounts as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 2) continue;
        $mp = (string)$parts[1];
        if (rtrim($mp, '/') === $path) {
            return (string)$parts[0];
        }
    }
    return null;
}

function enterprise_monitor_check_storage_paths(array $paths): array {
    $out = [];
    $ok = true;
    foreach ($paths as $p) {
        $path = is_string($p) ? trim($p) : '';
        if ($path === '') continue;
        $exists = file_exists($path);
        $isDir = $exists && is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);
        $total = $isDir ? @disk_total_space($path) : false;
        $free = $isDir ? @disk_free_space($path) : false;
        $totalI = is_int($total) ? $total : (is_float($total) ? (int)$total : 0);
        $freeI = is_int($free) ? $free : (is_float($free) ? (int)$free : 0);
        $usedPct = $totalI > 0 ? (int)round((1.0 - ($freeI / $totalI)) * 100) : null;
        $mountDevice = $isDir ? enterprise_monitor_get_mountpoint($path) : null;
        if (!$isDir || $totalI <= 0) $ok = false;
        $out[] = [
            'path' => $path,
            'exists' => $exists,
            'is_dir' => $isDir,
            'readable' => $readable,
            'writable' => $writable,
            'total_bytes' => $totalI,
            'free_bytes' => $freeI,
            'used_percent' => $usedPct,
            'mount_device' => $mountDevice,
        ];
    }
    return ['ok' => $ok, 'paths' => $out];
}

function enterprise_monitor_get_config_dir(): string {
    $cfgDir = '/data/config';
    try {
        if (function_exists('cue_autoload')) {
            $paths = cue_autoload('paths');
            $p0 = is_object($paths) && method_exists($paths, 'getConfigPath') ? (string)$paths->getConfigPath() : '';
            if ($p0 !== '') $cfgDir = $p0;
        }
    } catch (Throwable) {}
    return rtrim($cfgDir, '/');
}

function enterprise_monitor_read_biometrics_allowlist(string $file): array {
    if (!is_file($file) || !is_readable($file)) return [];
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return [];
    $items = $decoded['allowed_exact'] ?? $decoded['allowed_paths'] ?? null;
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $k => $v) {
        if (is_string($v)) {
            $p = trim($v);
            if ($p !== '') $out[$p] = true;
            continue;
        }
        if (is_string($k) && ($v === true || $v === 1 || $v === '1')) {
            $p = trim($k);
            if ($p !== '') $out[$p] = true;
        }
    }
    ksort($out);
    return array_keys($out);
}

function enterprise_monitor_biometrics_page_check(string $path): array {
    $path = trim($path);
    if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
    $path = preg_replace('#/+#', '/', $path) ?: $path;
    $path = rtrim($path, '/');
    if ($path === '') $path = '/';
    $out = ['path' => $path, 'ok' => false, 'error' => null];
    if (strpos($path, '/hub/') !== 0 && strpos($path, '/studio/') !== 0) {
        return ['path' => $path, 'ok' => true, 'skipped' => true];
    }
    $origUri = $_SERVER['REQUEST_URI'] ?? null;
    $origScript = $_SERVER['SCRIPT_NAME'] ?? null;
    $origPhpSelf = $_SERVER['PHP_SELF'] ?? null;
    $_SERVER['REQUEST_URI'] = $path;
    $_SERVER['SCRIPT_NAME'] = $path;
    $_SERVER['PHP_SELF'] = $path;
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        $pdo = function_exists('database_getConnectionById') ? database_getConnectionById('biometrics') : null;
        if ($pdo instanceof PDO) {
            $pdo->query('SELECT 1');
            $out['ok'] = true;
        } else {
            $out['error'] = 'biometrics_connection_unavailable';
        }
    } catch (Throwable $e) {
        $out['error'] = trim((string)$e->getMessage());
        if ($out['error'] === '') $out['error'] = 'exception';
    }
    if ($origUri !== null) $_SERVER['REQUEST_URI'] = $origUri; else unset($_SERVER['REQUEST_URI']);
    if ($origScript !== null) $_SERVER['SCRIPT_NAME'] = $origScript; else unset($_SERVER['SCRIPT_NAME']);
    if ($origPhpSelf !== null) $_SERVER['PHP_SELF'] = $origPhpSelf; else unset($_SERVER['PHP_SELF']);
    return $out;
}

function enterprise_monitor_check_biometrics_allowlisted_pages(): array {
    $cfgDir = enterprise_monitor_get_config_dir();
    $file = $cfgDir . '/biometrics-tenant-allowlist.json';
    $paths = enterprise_monitor_read_biometrics_allowlist($file);
    $paths = array_values(array_unique(array_filter($paths, static fn($p) => is_string($p) && trim($p) !== '')));
    if (empty($paths)) {
        return ['ok' => true, 'allowlist_file' => $file, 'count' => 0, 'failed' => 0, 'pages' => []];
    }
    $pages = [];
    $failed = 0;
    foreach ($paths as $p) {
        $r = enterprise_monitor_biometrics_page_check((string)$p);
        $pages[] = $r;
        if (($r['ok'] ?? false) !== true && empty($r['skipped'])) $failed++;
        if (count($pages) >= 80) break;
    }
    return ['ok' => ($failed === 0), 'allowlist_file' => $file, 'count' => count($pages), 'failed' => $failed, 'pages' => $pages];
}

function enterprise_monitor_read_json_file(string $path): ?array {
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function enterprise_monitor_latest_json_file(string $dir): ?string {
    if (!is_dir($dir)) return null;
    $items = @scandir($dir);
    if (!is_array($items)) return null;
    $files = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = rtrim($dir, '/') . '/' . $name;
        if (is_file($path) && substr($name, -5) === '.json') {
            $files[] = $path;
        }
    }
    if ($files === []) return null;
    sort($files);
    return $files[count($files) - 1];
}

function enterprise_monitor_infra_expected_public_ports(): array {
    return [
        'local' => [
            'tcp:21','tcp:22','tcp:25','tcp:53','tcp:80','tcp:110','tcp:111','tcp:143','tcp:443',
            'tcp:465','tcp:587','tcp:993','tcp:995','tcp:19091','tcp:19999','tcp:20048','tcp:2049',
            'tcp:2077','tcp:2078','tcp:2079','tcp:2080','tcp:2082','tcp:2083','tcp:2086','tcp:2087',
            'tcp:2091','tcp:2095','tcp:2096','tcp:3010','tcp:38177','tcp:4190','tcp:4222','tcp:42421',
            'tcp:45451','tcp:4789','tcp:59645','tcp:7474','tcp:7687','tcp:7880','tcp:7881','tcp:8090',
            'tcp:8222','tcp:8223','tcp:8445','tcp:8787',
            'udp:111','udp:20048','udp:35766','udp:37607','udp:43380','udp:4789','udp:48836','udp:53',
        ],
        'metahumans.one' => [
            'tcp:21','tcp:22','tcp:25','tcp:53','tcp:80','tcp:110','tcp:111','tcp:143','tcp:443',
            'tcp:465','tcp:587','tcp:993','tcp:995','tcp:19091','tcp:19999','tcp:20048','tcp:2049',
            'tcp:2077','tcp:2078','tcp:2079','tcp:2080','tcp:2082','tcp:2083','tcp:2086','tcp:2087',
            'tcp:2091','tcp:2095','tcp:2096','tcp:3010','tcp:38177','tcp:4190','tcp:4222','tcp:42421',
            'tcp:45451','tcp:4789','tcp:59645','tcp:7474','tcp:7687','tcp:7880','tcp:7881','tcp:8090',
            'tcp:8222','tcp:8223','tcp:8445','tcp:8787',
            'udp:111','udp:20048','udp:35766','udp:37607','udp:43380','udp:4789','udp:48836','udp:53',
        ],
        'superhumans.one' => [
            'tcp:21','tcp:22','tcp:53','tcp:80','tcp:111','tcp:443','tcp:4789','tcp:7880','tcp:7881',
            'tcp:8011','tcp:8080','tcp:8081','tcp:8089','tcp:9020','tcp:9091','tcp:9231','tcp:10250','tcp:11434',
            'udp:111','udp:4789','udp:53','udp:8472',
        ],
        'api.superhumans.one' => [
            'tcp:22','tcp:53','tcp:80','tcp:443','tcp:19999','tcp:4789','tcp:6443','tcp:8080',
            'udp:4789','udp:53',
        ],
        'ingress.superhumans.one' => [
            'tcp:22','tcp:53','tcp:80','tcp:443','tcp:19999','tcp:3478','tcp:4789','tcp:5349','tcp:8080',
            'udp:3478','udp:4789','udp:53','udp:5349',
        ],
        'rke-cp-1.superhumans.one' => [
            'tcp:22','tcp:53','tcp:19999','tcp:4789','tcp:6443','tcp:8080','tcp:9091','tcp:9345','tcp:10250','tcp:10260',
            'udp:4789','udp:53','udp:8472',
        ],
        'rke-cp-2.superhumans.one' => [
            'tcp:22','tcp:53','tcp:19999','tcp:4789','tcp:6443','tcp:9091','tcp:9345','tcp:10250','tcp:10260',
            'udp:4789','udp:53','udp:8472',
        ],
        'superbrains.one' => [
            'tcp:22','tcp:53','tcp:111','tcp:19999','tcp:4789','tcp:8011','tcp:9091','tcp:10250',
            'tcp:35099','tcp:35179','tcp:35867','tcp:38561','tcp:40091','tcp:40583','tcp:41193','tcp:43135',
            'tcp:46537','tcp:48845','tcp:50827','tcp:53983','tcp:55111','tcp:55769','tcp:59881',
            'udp:111','udp:4789','udp:53','udp:8472',
        ],
    ];
}

function enterprise_monitor_infra_port_number(string $local): ?int {
    if (!preg_match('/:(\d+)$/', trim($local), $m)) return null;
    $port = (int)$m[1];
    return ($port > 0 && $port <= 65535) ? $port : null;
}

function enterprise_monitor_infra_bind_host(string $local): string {
    $local = trim($local);
    if ($local === '') return '';
    if (preg_match('/^\[(.+)\]:(\d+)$/', $local, $m)) return trim((string)$m[1]);
    $pos = strrpos($local, ':');
    if ($pos === false) return $local;
    return trim(substr($local, 0, $pos));
}

function enterprise_monitor_infra_is_private_host(string $host): bool {
    $host = trim($host, '[] ');
    if ($host === '' || $host === '*' || $host === '0.0.0.0' || $host === '::') return false;
    if ($host === '127.0.0.1' || $host === '::1' || strcasecmp($host, 'localhost') === 0) return true;
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $long = ip2long($host);
        if (!is_int($long)) return false;
        foreach ([['10.0.0.0', '10.255.255.255'], ['172.16.0.0', '172.31.255.255'], ['192.168.0.0', '192.168.255.255'], ['127.0.0.0', '127.255.255.255']] as [$a, $b]) {
            $al = ip2long($a);
            $bl = ip2long($b);
            if (is_int($al) && is_int($bl) && $long >= $al && $long <= $bl) return true;
        }
        return false;
    }
    $hostLower = strtolower($host);
    return str_starts_with($hostLower, 'fc') || str_starts_with($hostLower, 'fd') || str_starts_with($hostLower, 'fe80:');
}

function enterprise_monitor_infra_public_port_set(array $snapshot): array {
    $set = [];
    foreach ((array)($snapshot['ports']['listening'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $proto = strtolower(trim((string)($row['proto'] ?? '')));
        $local = trim((string)($row['local'] ?? ''));
        if ($proto === '' || $local === '') continue;
        $port = enterprise_monitor_infra_port_number($local);
        if ($port === null) continue;
        $bindHost = enterprise_monitor_infra_bind_host($local);
        $isWildcard = ($bindHost === '0.0.0.0' || $bindHost === '::' || $bindHost === '*' || $bindHost === '');
        $isPublic = !$isWildcard && !enterprise_monitor_infra_is_private_host($bindHost);
        if ($isWildcard || $isPublic) {
            $set[$proto . ':' . $port] = true;
        }
    }
    ksort($set);
    return $set;
}

function enterprise_monitor_infra_check_dashboard(): array {
    $reportsDir = '/home/onemeta/public_html/gear/infra/drift/reports';
    $driftDir = '/home/onemeta/public_html/gear/infra/drift/drift-reports';
    $expectedPublic = enterprise_monitor_infra_expected_public_ports();
    $criticalHosts = [];
    $staleHosts = [];
    $exposureHosts = [];
    $driftHosts = [];
    $hosts = 0;

    $latestDriftPath = enterprise_monitor_latest_json_file($driftDir);
    $latestDrift = $latestDriftPath !== null ? enterprise_monitor_read_json_file($latestDriftPath) : null;
    $driftMap = is_array($latestDrift['hosts'] ?? null) ? $latestDrift['hosts'] : [];

    $dirs = @scandir($reportsDir);
    if (!is_array($dirs)) {
        return ['ok' => false, 'error' => 'reports_dir_missing', 'route' => '/gear/settings/infra.php'];
    }

    foreach ($dirs as $host) {
        if ($host === '.' || $host === '..') continue;
        $hostDir = rtrim($reportsDir, '/') . '/' . $host;
        if (!is_dir($hostDir)) continue;
        $latestSnapshotPath = enterprise_monitor_latest_json_file($hostDir);
        if ($latestSnapshotPath === null) continue;
        $snapshot = enterprise_monitor_read_json_file($latestSnapshotPath);
        if (!is_array($snapshot)) continue;
        $hosts++;

        $causes = [];
        $observedAt = isset($snapshot['observed_at']) ? strtotime((string)$snapshot['observed_at']) : false;
        $ageSeconds = is_int($observedAt) ? max(0, time() - $observedAt) : null;
        if (!is_int($ageSeconds) || $ageSeconds > 7 * 86400) {
            $staleHosts[] = (string)$host;
            $causes[] = 'snapshot stale';
        }

        $publicSet = enterprise_monitor_infra_public_port_set($snapshot);
        $expectedSet = [];
        foreach (($expectedPublic[$host] ?? []) as $key) {
            $expectedSet[(string)$key] = true;
        }
        $unexpected = [];
        if ($expectedSet === []) {
            if ($publicSet !== []) {
                $unexpected = array_keys($publicSet);
                $causes[] = 'host missing explicit exposure profile';
            }
        } else {
            foreach ($publicSet as $key => $_) {
                if (!isset($expectedSet[$key])) $unexpected[] = $key;
            }
            if ($unexpected !== []) {
                $causes[] = 'unexpected public port';
            }
        }
        if ($unexpected !== []) {
            $exposureHosts[] = ['host' => (string)$host, 'ports' => array_slice($unexpected, 0, 8)];
        }

        $counts = is_array($driftMap[$host]['counts'] ?? null) ? $driftMap[$host]['counts'] : [];
        $driftTotal = 0;
        foreach ($counts as $value) {
            $driftTotal += (int)$value;
        }
        if ($driftTotal > 0) {
            $driftHosts[] = (string)$host;
        }

        if ($causes !== []) {
            $criticalHosts[] = ['host' => (string)$host, 'causes' => $causes, 'age_s' => $ageSeconds];
        }
    }

    return [
        'ok' => count($criticalHosts) === 0,
        'route' => '/gear/settings/infra.php',
        'hosts' => $hosts,
        'critical_hosts' => $criticalHosts,
        'stale_hosts' => $staleHosts,
        'drift_hosts' => $driftHosts,
        'exposure_hosts' => $exposureHosts,
        'latest_drift_path' => $latestDriftPath,
        'latest_drift_generated_at' => is_array($latestDrift) ? (string)($latestDrift['generated_at'] ?? '') : '',
    ];
}

$results['checks']['block_storage_paths'] = enterprise_monitor_check_storage_paths(['/mysql', '/data', '/vector', '/graph']);
$results['checks']['biometrics_allowlist_pages'] = enterprise_monitor_check_biometrics_allowlisted_pages();
$results['checks']['infra_dashboard'] = enterprise_monitor_infra_check_dashboard();

// Determine overall status
$overallStatus = 'healthy';
$criticalChecks = [
    'ollama_superhumans',
    'personaplex_port',
    'livekit_metahumans',
    'personaplex_assets',
    'plugnmeet_metahumans',
    'k8s_cp_rke-cp-1',
    'k8s_cp_rke-cp-2',
    'db_3306',
    'db_3307',
    'block_storage_paths',
    'biometrics_allowlist_pages',
];
$warningChecks = [
    'netdata_endpoints',
    'opencost_http_endpoints',
    'opencost_kubectl',
    'infra_dashboard',
];
foreach ($criticalChecks as $checkKey) {
    $check = $results['checks'][$checkKey] ?? null;
    if (!is_array($check)) {
        $overallStatus = 'critical';
        break;
    }
    if (isset($check['running']) && $check['running'] === false) {
        $overallStatus = 'critical';
        break;
    }
    if (isset($check['reachable']) && !$check['reachable']) {
        $overallStatus = 'critical';
        break;
    }
    if (isset($check['open']) && !$check['open']) {
        $overallStatus = 'critical';
        break;
    }
    if (isset($check['ok']) && !$check['ok']) {
        $overallStatus = 'critical';
        break;
    }
}
if ($overallStatus === 'healthy') {
    foreach ($warningChecks as $checkKey) {
        $check = $results['checks'][$checkKey] ?? null;
        if (!is_array($check)) continue;
        if (isset($check['ok']) && $check['ok'] === false) {
            $overallStatus = 'warning';
            break;
        }
    }
}
$results['overall_status'] = $overallStatus;

// Output as JSON for API consumption
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

// HTML Dashboard Output
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($refreshInterval > 0) { ?>
        <meta http-equiv="refresh" content="<?php echo (int)$refreshInterval; ?>">
    <?php } ?>
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            color: #e6f6ff;
            min-height: 100vh;
            padding: 0;
        }
        main.main-content {
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #00d4ff, #0099cc);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .status-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-healthy { background: #00c853; color: #000; }
        .status-critical { background: #ff1744; color: #fff; }
        .status-warning { background: #00c853; color: #000; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0,212,255,0.1);
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .card-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #fff;
        }
        .indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
        }
        .indicator-success { background: #00c853; box-shadow: 0 0 8px #00c853; }
        .indicator-error { background: #ff1744; box-shadow: 0 0 8px #ff1744; }
        .indicator-warning { background: #00c853; box-shadow: 0 0 8px #00c853; }
        .metric {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .metric:last-child {
            border-bottom: none;
        }
        .metric-label {
            color: rgba(255,255,255,0.7);
            font-size: 0.9em;
        }
        .metric-value {
            color: #fff;
            font-weight: 500;
            font-size: 0.9em;
        }
        .timestamp {
            text-align: center;
            color: rgba(255,255,255,0.5);
            font-size: 0.85em;
            margin-top: 20px;
            padding: 16px;
            background: rgba(255,255,255,0.03);
            border-radius: 12px;
        }
        .section-title {
            font-size: 1.4em;
            font-weight: 600;
            margin: 30px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(0,212,255,0.3);
            color: #00d4ff;
        }
        .alert-log {
            max-height: 300px;
            overflow-y: auto;
            background: rgba(0,0,0,0.2);
            border-radius: 8px;
            padding: 12px;
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
        }
        .alert-entry {
            padding: 6px 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .alert-entry:last-child {
            border-bottom: none;
        }
        .alert-time {
            color: rgba(255,255,255,0.5);
            font-size: 0.85em;
        }
        .alert-critical { color: #ff1744; }
        .alert-warning { color: #00d4ff; }
        .alert-info { color: #00d4ff; }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 10px;
            border: 1px solid rgba(0, 212, 255, 0.35);
            background: rgba(0, 212, 255, 0.10);
            color: #e6f6ff;
            padding: 10px 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover {
            background: rgba(0, 212, 255, 0.18);
        }
        .btn-danger {
            border-color: rgba(255, 23, 68, 0.45);
            background: rgba(255, 23, 68, 0.12);
        }
        .btn-danger:hover {
            background: rgba(255, 23, 68, 0.18);
        }
        .flash {
            margin: 16px 0 0;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.12);
            background: rgba(0,0,0,0.22);
            color: #e6f6ff;
        }
        .flash-success { border-color: rgba(0, 200, 83, 0.45); background: rgba(0, 200, 83, 0.10); }
        .flash-error { border-color: rgba(255, 23, 68, 0.45); background: rgba(255, 23, 68, 0.10); }
        .flash-hidden { display: none; }
    </style>
</head>
<body>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
    <main class="main-content">
    <div class="container">
        <div class="header">
            <h1>🔍 Enterprise Monitoring Dashboard</h1>
            <p style="color: rgba(255,255,255,0.7); margin-top: 10px;">
                Real-time infrastructure health monitoring
            </p>
            <div style="margin-top: 15px;">
                <span class="status-badge status-<?php echo $overallStatus; ?>">
                    <?php echo strtoupper($overallStatus); ?>
                </span>
            </div>
        </div>
        <div id="enterprise-monitor-flash" class="flash<?php echo is_array($enterpriseMonitorFlash) ? (' flash-' . htmlspecialchars((string)($enterpriseMonitorFlash['type'] ?? 'info'))) : ' flash-hidden'; ?>">
            <?php echo is_array($enterpriseMonitorFlash) ? htmlspecialchars((string)($enterpriseMonitorFlash['message'] ?? '')) : ''; ?>
        </div>

        <!-- Overall Status Section -->
        <div class="section-title">📊 Overall System Status</div>
        <div class="grid">
            <?php
            $statusCards = [
                ['title' => 'Ollama (superhumans)', 'check' => 'ollama_superhumans', 'http' => true],
                ['title' => 'PersonaPlex (meta.superhumans.one) port 8998', 'check' => 'personaplex_port', 'tcp' => true],
                ['title' => 'PersonaPlex assets (meta.superhumans.one)', 'check' => 'personaplex_assets', 'ok' => true],
                ['title' => 'PlugNMeet (metahumans.one UI)', 'check' => 'plugnmeet_metahumans', 'http' => true],
                ['title' => 'LiveKit (meet.metahumans.one / usa.metahumans.one)', 'check' => 'livekit_metahumans', 'service' => true],
                ['title' => 'K8s Control Plane 1', 'check' => 'k8s_cp_rke-cp-1', 'k8s' => true],
                ['title' => 'K8s Control Plane 2', 'check' => 'k8s_cp_rke-cp-2', 'k8s' => true],
                ['title' => 'MySQL 3306 (local)', 'check' => 'db_3306', 'tcp' => true],
                ['title' => 'MySQL 3307 (local)', 'check' => 'db_3307', 'tcp' => true],
            ];

            foreach ($statusCards as $card) {
                $checkData = $results['checks'][$card['check']] ?? [];
                
                if (isset($card['service'])) {
                    $isActive = $checkData['running'] ?? false;
                    $statusClass = $isActive ? 'success' : 'error';
                    $statusText = $isActive ? 'ACTIVE' : 'INACTIVE';
                } elseif (isset($card['http'])) {
                    $isOk = $checkData['ok'] ?? false;
                    $statusClass = $isOk ? 'success' : 'error';
                    $statusText = $isOk ? 'OK' : 'ERROR';
                } elseif (isset($card['ok'])) {
                    $isOk = $checkData['ok'] ?? false;
                    $statusClass = $isOk ? 'success' : 'error';
                    $statusText = $isOk ? 'OK' : 'MISSING';
                } elseif (isset($card['k8s'])) {
                    $isReachable = $checkData['reachable'] ?? false;
                    $statusClass = $isReachable ? 'success' : 'error';
                    $statusText = $isReachable ? 'REACHABLE' : 'UNREACHABLE';
                } elseif (isset($card['tcp'])) {
                    $isOpen = $checkData['open'] ?? false;
                    $statusClass = $isOpen ? 'success' : 'error';
                    $statusText = $isOpen ? 'OPEN' : 'CLOSED';
                } else {
                    $statusClass = 'warning';
                    $statusText = 'UNKNOWN';
                }
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><?php echo htmlspecialchars($card['title']); ?></span>
                        <span class="indicator indicator-<?php echo $statusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Status</span>
                        <span class="metric-value status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                    </div>
                    <?php if ($card['check'] === 'personaplex_assets' && $statusClass !== 'success') { ?>
                        <div style="margin-top: 10px; color: rgba(255,255,255,0.70); font-size: 12px; line-height: 1.3;">
                            <?php
                            $detail = isset($checkData['detail']) ? trim((string)$checkData['detail']) : '';
                            $exit = isset($checkData['ssh']['exit']) ? (string)$checkData['ssh']['exit'] : '';
                            echo htmlspecialchars(($detail !== '' ? $detail : 'no_detail') . ($exit !== '' ? (' (ssh_exit=' . $exit . ')') : ''));
                            ?>
                        </div>
                    <?php } ?>
                </div>
                <?php
            }
            ?>
        </div>

        <!-- SSH Connectivity Section -->
        <div class="section-title">🔌 SSH Connectivity</div>
        <div class="grid">
            <?php
            $sshCards = [
                ['title' => 'superhumans.one SSH (22)', 'check' => 'ssh_superhumans', 'tcp' => true],
                ['title' => 'superbrains.one SSH (22)', 'check' => 'ssh_superbrains', 'tcp' => true],
                ['title' => 'metahumans.one SSH (22)', 'check' => 'ssh_metahumans', 'tcp' => true],
                ['title' => 'api.superhumans.one SSH (22)', 'check' => 'ssh_api_superhumans', 'tcp' => true],
                ['title' => 'ingress.superhumans.one SSH (22)', 'check' => 'ssh_ingress_superhumans', 'tcp' => true],
                ['title' => 'rke-cp-1 SSH (22)', 'check' => 'k8s_cp_rke-cp-1', 'k8s_ssh' => true],
                ['title' => 'rke-cp-2 SSH (22)', 'check' => 'k8s_cp_rke-cp-2', 'k8s_ssh' => true],
            ];

            foreach ($sshCards as $card) {
                $checkData = $results['checks'][$card['check']] ?? [];
                
                if (isset($card['k8s_ssh'])) {
                    $isReachable = $checkData['ssh_port_open'] ?? false;
                } elseif (isset($card['tcp'])) {
                    $isReachable = $checkData['open'] ?? false;
                } else {
                    $isReachable = $checkData['reachable'] ?? false;
                }

                $statusClass = $isReachable ? 'success' : 'error';
                $statusText = $isReachable ? 'OPEN' : 'CLOSED';
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><?php echo htmlspecialchars($card['title']); ?></span>
                        <span class="indicator indicator-<?php echo $statusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Connection</span>
                        <span class="metric-value status-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="section-title">📈 Netdata</div>
        <div class="grid">
            <?php
            $nd = $results['checks']['netdata_endpoints'] ?? [];
            $targets = is_array($nd['targets'] ?? null) ? $nd['targets'] : [];
            foreach ($targets as $t) {
                if (!is_array($t)) continue;
                $name = (string)($t['name'] ?? '');
                $host = (string)($t['host'] ?? '');
                $ok = ($t['ok'] ?? false) === true;
                $directOk = ($t['direct_ok'] ?? false) === true;
                $tcpOpen = is_array($t['tcp'] ?? null) ? (($t['tcp']['open'] ?? false) === true) : false;
                $tcpErr = is_array($t['tcp'] ?? null) ? (string)($t['tcp']['error'] ?? '') : '';
                $httpOk = is_array($t['http'] ?? null) ? (($t['http']['ok'] ?? false) === true) : false;
                $httpCode = is_array($t['http'] ?? null) ? (string)($t['http']['http_code'] ?? '') : '';
                $httpErr = is_array($t['http'] ?? null) ? (string)($t['http']['error'] ?? '') : '';
                $sshLocal = is_array($t['ssh_local'] ?? null) ? $t['ssh_local'] : null;
                $sshOk = is_array($sshLocal) ? (($sshLocal['ok'] ?? false) === true) : false;
                $sshExit = is_array($sshLocal) ? (string)($sshLocal['exit'] ?? '') : '';
                $sshErr = is_array($sshLocal) ? (string)($sshLocal['error'] ?? '') : '';
                $ver = isset($t['version']) && is_string($t['version']) ? trim((string)$t['version']) : '';
                $method = $directOk ? 'direct_http' : ($sshOk ? 'ssh_local' : 'none');
                $authDenied = (!$sshOk && is_string($sshErr) && stripos($sshErr, 'permission denied') !== false);
                $statusClass = $ok ? 'success' : 'error';
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><?php echo htmlspecialchars($name !== '' ? $name : $host); ?> Netdata</span>
                        <span class="indicator indicator-<?php echo $statusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Operational</span>
                        <span class="metric-value status-<?php echo $ok ? 'success' : 'error'; ?>"><?php echo $ok ? 'YES' : ($authDenied ? 'UNVERIFIED' : 'NO'); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Verified via</span>
                        <span class="metric-value"><?php echo htmlspecialchars($method === 'ssh_local' ? 'SSH local (127.0.0.1)' : ($method === 'direct_http' ? 'Direct HTTP' : ($authDenied ? 'SSH denied' : '—'))); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">External TCP 19999</span>
                        <span class="metric-value status-<?php echo ($tcpOpen || $ok) ? 'success' : 'error'; ?>"><?php echo $tcpOpen ? 'OPEN' : 'CLOSED'; ?></span>
                    </div>
                    <?php if (!$tcpOpen && $tcpErr !== '') { ?>
                        <div class="metric">
                            <span class="metric-label">TCP error</span>
                            <span class="metric-value status-<?php echo $ok ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($tcpErr); ?></span>
                        </div>
                    <?php } ?>
                    <div class="metric">
                        <span class="metric-label">External HTTP /api/v1/info</span>
                        <span class="metric-value status-<?php echo ($httpOk || $ok) ? 'success' : 'error'; ?>"><?php echo $httpOk ? 'OK' : 'ERROR'; ?></span>
                    </div>
                    <?php if (!$httpOk) { ?>
                        <div class="metric">
                            <span class="metric-label">HTTP code</span>
                            <span class="metric-value"><?php echo htmlspecialchars($httpCode !== '' ? $httpCode : '—'); ?></span>
                        </div>
                        <?php if ($httpErr !== '') { ?>
                            <div class="metric">
                                <span class="metric-label">HTTP error</span>
                                <span class="metric-value status-<?php echo $ok ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($httpErr); ?></span>
                            </div>
                        <?php } ?>
                    <?php } ?>
                    <div class="metric">
                        <span class="metric-label">Version</span>
                        <span class="metric-value"><?php echo htmlspecialchars($ver !== '' ? $ver : '—'); ?></span>
                    </div>
                    <?php if (is_array($sshLocal)) { ?>
                        <div class="metric">
                            <span class="metric-label">SSH local check</span>
                            <span class="metric-value status-<?php echo $sshOk ? 'success' : 'error'; ?>"><?php echo $sshOk ? 'OK' : 'ERROR'; ?><?php echo $sshExit !== '' ? htmlspecialchars(' (exit=' . $sshExit . ')') : ''; ?></span>
                        </div>
                        <?php if (!$sshOk && $sshErr !== '') { ?>
                            <div class="metric">
                                <span class="metric-label">SSH error</span>
                                <span class="metric-value status-error"><?php echo htmlspecialchars($sshErr); ?></span>
                            </div>
                        <?php } ?>
                    <?php } ?>
                    <?php if ($ok && (!$tcpOpen || !$httpOk)) { ?>
                        <div style="margin-top:10px; color: rgba(255,255,255,0.70); font-size: 12px; line-height: 1.3;">
                            Netdata is running but not exposed publicly on port 19999.
                        </div>
                    <?php } ?>
                </div>
                <?php
            }
            ?>
            <?php
            $infra = $results['checks']['infra_dashboard'] ?? [];
            if (is_array($infra)) {
                $infraOk = ($infra['ok'] ?? false) === true;
                $infraCriticalHosts = is_array($infra['critical_hosts'] ?? null) ? $infra['critical_hosts'] : [];
                $infraStaleHosts = is_array($infra['stale_hosts'] ?? null) ? $infra['stale_hosts'] : [];
                $infraExposureHosts = is_array($infra['exposure_hosts'] ?? null) ? $infra['exposure_hosts'] : [];
                $infraDriftHosts = is_array($infra['drift_hosts'] ?? null) ? $infra['drift_hosts'] : [];
                $infraStatusClass = $infraOk ? 'success' : 'error';
                $primaryCause = '—';
                if (!$infraOk && !empty($infraCriticalHosts) && is_array($infraCriticalHosts[0]['causes'] ?? null)) {
                    $primaryCause = implode(' · ', array_slice((array)$infraCriticalHosts[0]['causes'], 0, 2));
                }
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Infra Dashboard</span>
                        <span class="indicator indicator-<?php echo $infraStatusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Route</span>
                        <span class="metric-value"><a href="<?php echo htmlspecialchars((string)($infra['route'] ?? '/gear/settings/infra.php')); ?>" style="color: inherit;">/gear/settings/infra.php</a></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Operational</span>
                        <span class="metric-value status-<?php echo $infraStatusClass; ?>"><?php echo $infraOk ? 'OK' : 'ATTN'; ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Hosts observed</span>
                        <span class="metric-value"><?php echo (int)($infra['hosts'] ?? 0); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Critical hosts</span>
                        <span class="metric-value status-<?php echo $infraOk ? 'success' : 'error'; ?>"><?php echo count($infraCriticalHosts); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Stale snapshots</span>
                        <span class="metric-value status-<?php echo count($infraStaleHosts) === 0 ? 'success' : 'error'; ?>"><?php echo count($infraStaleHosts); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Exposure alerts</span>
                        <span class="metric-value status-<?php echo count($infraExposureHosts) === 0 ? 'success' : 'error'; ?>"><?php echo count($infraExposureHosts); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Drift hosts</span>
                        <span class="metric-value status-<?php echo count($infraDriftHosts) === 0 ? 'success' : 'warning'; ?>"><?php echo count($infraDriftHosts); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Primary cause</span>
                        <span class="metric-value status-<?php echo $infraOk ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($primaryCause); ?></span>
                    </div>
                    <?php if (!empty($infraStaleHosts)) { ?>
                        <div style="margin-top:10px; color: rgba(255,255,255,0.70); font-size: 12px; line-height: 1.35;">
                            Snapshot data is stale for <?php echo htmlspecialchars(implode(', ', array_slice($infraStaleHosts, 0, 4))); ?><?php echo count($infraStaleHosts) > 4 ? ' …' : ''; ?>.
                        </div>
                    <?php } ?>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="section-title">💰 OpenCost</div>
        <div class="grid">
            <?php
            $oc = $results['checks']['opencost_http_endpoints'] ?? [];
            $targets = is_array($oc['targets'] ?? null) ? $oc['targets'] : [];
            $k = $results['checks']['opencost_kubectl'] ?? [];
            $opencostKubectlOk = is_array($k) && (($k['ok'] ?? false) === true);
            foreach ($targets as $t) {
                if (!is_array($t)) continue;
                $name = (string)($t['name'] ?? '');
                $host = (string)($t['host'] ?? '');
                $ok = ($t['ok'] ?? false) === true;
                $tcpOpen = is_array($t['tcp'] ?? null) ? (($t['tcp']['open'] ?? false) === true) : false;
                $tcpErr = is_array($t['tcp'] ?? null) ? (string)($t['tcp']['error'] ?? '') : '';
                $httpOk = is_array($t['http'] ?? null) ? (($t['http']['ok'] ?? false) === true) : false;
                $httpCode = is_array($t['http'] ?? null) ? (string)($t['http']['http_code'] ?? '') : '';
                $httpErr = is_array($t['http'] ?? null) ? (string)($t['http']['error'] ?? '') : '';
                $statusClass = ($ok || $opencostKubectlOk) ? 'success' : 'error';
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><?php echo htmlspecialchars($name !== '' ? $name : $host); ?> OpenCost HTTP</span>
                        <span class="indicator indicator-<?php echo $statusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">TCP 9003</span>
                        <span class="metric-value status-<?php echo ($tcpOpen || $opencostKubectlOk) ? 'success' : 'error'; ?>"><?php echo $tcpOpen ? 'OPEN' : 'CLOSED'; ?></span>
                    </div>
                    <?php if (!$tcpOpen && $tcpErr !== '') { ?>
                        <div class="metric">
                            <span class="metric-label">TCP error</span>
                            <span class="metric-value status-<?php echo $opencostKubectlOk ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($tcpErr); ?></span>
                        </div>
                    <?php } ?>
                    <div class="metric">
                        <span class="metric-label">HTTP /metrics</span>
                        <span class="metric-value status-<?php echo ($httpOk || $opencostKubectlOk) ? 'success' : 'error'; ?>"><?php echo $httpOk ? 'OK' : 'ERROR'; ?></span>
                    </div>
                    <?php if (!$httpOk) { ?>
                        <div class="metric">
                            <span class="metric-label">HTTP code</span>
                            <span class="metric-value"><?php echo htmlspecialchars($httpCode !== '' ? $httpCode : '—'); ?></span>
                        </div>
                        <?php if ($httpErr !== '') { ?>
                            <div class="metric">
                                <span class="metric-label">HTTP error</span>
                                <span class="metric-value status-<?php echo $opencostKubectlOk ? 'success' : 'error'; ?>"><?php echo htmlspecialchars($httpErr); ?></span>
                            </div>
                        <?php } ?>
                    <?php } ?>
                    <div class="metric">
                        <span class="metric-label">Marker</span>
                        <span class="metric-value"><?php echo htmlspecialchars((string)($t['marker'] ?? '—')); ?></span>
                    </div>
                    <?php if ($opencostKubectlOk && (!$tcpOpen || !$httpOk)) { ?>
                        <div style="margin-top:10px; color: rgba(255,255,255,0.70); font-size: 12px; line-height: 1.3;">
                            OpenCost is running in Kubernetes but not exposed publicly on port 9003.
                        </div>
                    <?php } ?>
                </div>
                <?php
            }

            $nodes = is_array($k['nodes'] ?? null) ? $k['nodes'] : [];
            foreach ($nodes as $n) {
                if (!is_array($n)) continue;
                $nodeName = (string)($n['node'] ?? '');
                $host = (string)($n['host'] ?? '');
                $ok = ($n['ok'] ?? false) === true;
                $sshOk = is_array($n['ssh'] ?? null) ? (($n['ssh']['ok'] ?? false) === true) : false;
                $exit = is_array($n['ssh'] ?? null) ? (string)($n['ssh']['exit'] ?? '') : '';
                $pods = ($n['pods_found'] ?? false) === true;
                $svcs = ($n['svcs_found'] ?? false) === true;
                $podsReady = ($n['pods_ready'] ?? false) === true;
                $epsFound = ($n['endpoints_found'] ?? false) === true;
                $epsReady = ($n['endpoints_ready'] ?? false) === true;
                $detail = isset($n['detail']) ? trim((string)$n['detail']) : '';
                $authDenied = (!$sshOk && $detail !== '' && stripos($detail, 'permission denied') !== false);
                $statusClass = $ok ? 'success' : 'error';
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><?php echo htmlspecialchars(($nodeName !== '' ? $nodeName : $host) . ' OpenCost (kubectl)'); ?></span>
                        <span class="indicator indicator-<?php echo $statusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">SSH</span>
                        <span class="metric-value status-<?php echo $sshOk ? 'success' : 'error'; ?>"><?php echo $sshOk ? 'OK' : 'ERROR'; ?><?php echo $exit !== '' ? htmlspecialchars(' (exit=' . $exit . ')') : ''; ?></span>
                    </div>
                    <?php if ($authDenied) { ?>
                        <div class="metric">
                            <span class="metric-label">SSH auth</span>
                            <span class="metric-value status-error">Permission denied</span>
                        </div>
                    <?php } ?>
                    <div class="metric">
                        <span class="metric-label">Pods</span>
                        <span class="metric-value status-<?php echo $pods ? 'success' : 'error'; ?>"><?php echo $pods ? 'FOUND' : 'NOT FOUND'; ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Pods ready</span>
                        <span class="metric-value status-<?php echo $podsReady ? 'success' : 'error'; ?>"><?php echo $podsReady ? 'YES' : 'NO'; ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Services</span>
                        <span class="metric-value status-<?php echo $svcs ? 'success' : 'error'; ?>"><?php echo $svcs ? 'FOUND' : 'NOT FOUND'; ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Endpoints</span>
                        <span class="metric-value status-<?php echo $epsFound ? 'success' : 'error'; ?>"><?php echo $epsFound ? 'FOUND' : 'NOT FOUND'; ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Endpoints ready</span>
                        <span class="metric-value status-<?php echo $epsReady ? 'success' : 'error'; ?>"><?php echo $epsReady ? 'YES' : 'NO'; ?></span>
                    </div>
                    <?php if ($detail !== '' && !$ok) { ?>
                        <div style="margin-top: 10px; color: rgba(255,255,255,0.70); font-size: 12px; line-height: 1.3; white-space: pre-wrap;"><?php echo htmlspecialchars($detail); ?></div>
                    <?php } ?>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="section-title">🗄️ Block Storage</div>
        <div class="grid">
            <?php
            $storage = $results['checks']['block_storage_paths'] ?? [];
            $paths = is_array($storage['paths'] ?? null) ? $storage['paths'] : [];
            foreach ($paths as $p) {
                if (!is_array($p)) continue;
                $path = (string)($p['path'] ?? '');
                $ok = (($p['is_dir'] ?? false) === true) && ((int)($p['total_bytes'] ?? 0) > 0);
                $statusClass = $ok ? 'success' : 'error';
                $total = (int)($p['total_bytes'] ?? 0);
                $free = (int)($p['free_bytes'] ?? 0);
                $usedPct = $p['used_percent'] ?? null;
                $mount = (string)($p['mount_device'] ?? '');
                ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><?php echo htmlspecialchars($path !== '' ? $path : 'storage'); ?></span>
                        <span class="indicator indicator-<?php echo $statusClass; ?>"></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Mounted</span>
                        <span class="metric-value"><?php echo htmlspecialchars($mount !== '' ? $mount : 'unknown'); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Total</span>
                        <span class="metric-value"><?php echo htmlspecialchars(enterprise_monitor_format_bytes($total)); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Free</span>
                        <span class="metric-value"><?php echo htmlspecialchars(enterprise_monitor_format_bytes($free)); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Used</span>
                        <span class="metric-value"><?php echo is_int($usedPct) ? htmlspecialchars((string)$usedPct . '%') : '—'; ?></span>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>

        <div class="section-title">🧬 Biometrics Allowlist Pages</div>
        <div class="card">
            <?php
            $allow = $results['checks']['biometrics_allowlist_pages'] ?? [];
            $file = (string)($allow['allowlist_file'] ?? '');
            $count = (int)($allow['count'] ?? 0);
            $failed = (int)($allow['failed'] ?? 0);
            $ok = ($allow['ok'] ?? false) === true;
            ?>
            <div class="card-header">
                <span class="card-title">Tenant pages allowed to use biometrics</span>
                <span class="indicator indicator-<?php echo $ok ? 'success' : 'error'; ?>"></span>
            </div>
            <div class="metric">
                <span class="metric-label">Allowlist file</span>
                <span class="metric-value"><?php echo htmlspecialchars($file !== '' ? $file : 'unknown'); ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Checked</span>
                <span class="metric-value"><?php echo htmlspecialchars((string)$count); ?></span>
            </div>
            <div class="metric">
                <span class="metric-label">Failed</span>
                <span class="metric-value"><?php echo htmlspecialchars((string)$failed); ?></span>
            </div>
            <?php
            $pages = is_array($allow['pages'] ?? null) ? $allow['pages'] : [];
            $fails = array_values(array_filter($pages, static function ($r) {
                if (!is_array($r)) return false;
                if (!empty($r['skipped'])) return false;
                return (($r['ok'] ?? false) !== true);
            }));
            if (!empty($fails)) {
                echo '<div style="margin-top:12px; color: rgba(255,255,255,0.80); font-size: 0.9em;">';
                echo '<div style="margin-bottom:8px; font-weight:700;">Failures</div>';
                foreach ($fails as $r) {
                    $p = (string)($r['path'] ?? '');
                    $err = (string)($r['error'] ?? 'failed');
                    echo '<div class="metric"><span class="metric-label">' . htmlspecialchars($p) . '</span><span class="metric-value status-error">' . htmlspecialchars($err) . '</span></div>';
                }
                echo '</div>';
            } elseif ($count === 0) {
                echo '<div style="margin-top:12px; color: rgba(255,255,255,0.65); font-size: 0.9em;">Allowlist is empty.</div>';
            }
            ?>
        </div>

        <!-- Alert Log Section -->
        <div class="section-title">🚨 Recent Alerts</div>
        <div class="card">
            <div style="display:flex; justify-content:flex-end; margin-bottom:12px;">
                <form method="post" class="js-clear-log-form" data-log-kind="alerts" style="margin:0;">
                    <input type="hidden" name="action" value="clear_alerts">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(enterprise_monitor_csrf()); ?>">
                    <button type="submit" class="btn btn-danger">Clear Log</button>
                </form>
            </div>
            <div class="alert-log" id="enterprise-monitor-alerts-preview">
                <?php
                $alerts = [];
                if (file_exists(MONITOR_ALERT_LOG)) {
                    $lines = file(MONITOR_ALERT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                    $lines = array_slice($lines, -20); // Last 20 alerts
                    foreach ($lines as $line) {
                        $alert = json_decode($line, true);
                        if ($alert) {
                            $alerts[] = $alert;
                        }
                    }
                }
                
                if (empty($alerts)) {
                    echo '<div class="alert-entry"><span style="color: rgba(255,255,255,0.5);">No recent alerts</span></div>';
                } else {
                    foreach (array_reverse($alerts) as $alert) {
                        $severityClass = 'alert-' . ($alert['severity'] ?? 'info');
                        $time = $alert['timestamp'] ?? 'Unknown';
                        $component = $alert['component'] ?? 'Unknown';
                        $message = $alert['message'] ?? 'No message';
                        
                        echo sprintf(
                            '<div class="alert-entry">' .
                            '<span class="alert-time">%s</span> ' .
                            '<span class="%s">[%s]</span> ' .
                            '<strong>%s:</strong> %s' .
                            '</div>',
                            htmlspecialchars($time),
                            $severityClass,
                            strtoupper($alert['severity'] ?? 'INFO'),
                            htmlspecialchars($component),
                            htmlspecialchars($message)
                        );
                    }
                }
                ?>
            </div>
        </div>

        <div class="section-title">📜 Log Files</div>
        <div class="grid">
            <?php
            $logFiles = enterprise_monitor_list_log_files();
            $logFiles = array_values(array_filter($logFiles, static function ($p) {
                if (!is_string($p) || $p === '') return false;
                $rp = realpath($p);
                if (!is_string($rp) || $rp === '') return false;
                $alerts = realpath(MONITOR_ALERT_LOG);
                if (is_string($alerts) && $alerts !== '' && $rp === $alerts) return false;
                return true;
            }));

            foreach ($logFiles as $p) {
                $state = enterprise_monitor_log_state_for_path($p);
                $sev = enterprise_monitor_log_severity_for_path($p);
                $indicator = enterprise_monitor_indicator_for_severity($sev);
                $size = @filesize($p);
                $size = is_int($size) ? $size : 0;
                $mtime = @filemtime($p);
                $mtime = is_int($mtime) ? $mtime : null;
                $preview = enterprise_monitor_log_preview($p);
                $logId = 'log-' . md5($p);
                ?>
                <div class="card" data-log-card="<?php echo htmlspecialchars($logId); ?>">
                    <div class="card-header" style="gap: 10px;">
                        <span class="card-title"><?php echo htmlspecialchars(basename($p)); ?></span>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span class="indicator indicator-<?php echo htmlspecialchars($indicator); ?>" data-log-indicator></span>
                            <form method="post" class="js-clear-log-form" data-log-kind="file" data-log-card="<?php echo htmlspecialchars($logId); ?>" style="margin:0;">
                                <input type="hidden" name="action" value="clear_log">
                                <input type="hidden" name="log" value="<?php echo htmlspecialchars($p); ?>">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(enterprise_monitor_csrf()); ?>">
                                <button type="submit" class="btn btn-danger">Clear Log</button>
                            </form>
                        </div>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Path</span>
                        <span class="metric-value"><?php echo htmlspecialchars($p); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Severity</span>
                        <span class="metric-value" data-log-severity><?php echo htmlspecialchars(strtoupper($sev)); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">State</span>
                        <span class="metric-value" data-log-state><?php echo $state === 'empty' ? 'EMPTY' : 'HAS CONTENT'; ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Size</span>
                        <span class="metric-value" data-log-size><?php echo htmlspecialchars(enterprise_monitor_format_bytes($size)); ?></span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Modified</span>
                        <span class="metric-value"><?php echo $mtime ? htmlspecialchars(gmdate('Y-m-d H:i:s \\U\\T\\C', $mtime)) : '—'; ?></span>
                    </div>
                    <div class="alert-log" data-log-preview style="margin-top: 14px; max-height: 220px;">
                        <?php
                        if ($state === 'empty') {
                            echo '<div class="alert-entry"><span style="color: rgba(255,255,255,0.5);">Log is empty</span></div>';
                        } elseif (trim($preview) === '') {
                            echo '<div class="alert-entry"><span style="color: rgba(255,255,255,0.5);">No preview available</span></div>';
                        } else {
                            echo '<pre style="margin:0; white-space:pre-wrap; word-break:break-word;">' . htmlspecialchars($preview) . '</pre>';
                        }
                        ?>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>

        <!-- Timestamp -->
        <div class="timestamp">
            Last updated: <?php echo $results['timestamp']; ?> | 
            Auto-refresh every <?php echo $refreshInterval; ?> seconds |
            <a href="?format=json" style="color: #00d4ff;">JSON API</a>
        </div>
    </div>
    </main>
    <script>
    (function () {
        const flashEl = document.getElementById('enterprise-monitor-flash');
        const setFlash = (type, message) => {
            if (!flashEl) return;
            flashEl.className = 'flash flash-' + (type || 'info');
            flashEl.textContent = message || '';
            flashEl.classList.remove('flash-hidden');
        };

        const emptyHtml = '<div class="alert-entry"><span style="color: rgba(255,255,255,0.5);">Log is empty</span></div>';
        const alertsEmptyHtml = '<div class="alert-entry"><span style="color: rgba(255,255,255,0.5);">No recent alerts</span></div>';

        document.querySelectorAll('.js-clear-log-form').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = form.querySelector('button[type="submit"]');
                if (button) button.disabled = true;
                try {
                    const resp = await fetch(window.location.href, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        credentials: 'same-origin'
                    });
                    const data = await resp.json();
                    if (!resp.ok || !data || !data.ok) {
                        throw new Error(data && data.flash && data.flash.message ? data.flash.message : 'Failed to clear log');
                    }

                    const kind = form.dataset.logKind || '';
                    if (kind === 'alerts') {
                        const preview = document.getElementById('enterprise-monitor-alerts-preview');
                        if (preview) preview.innerHTML = alertsEmptyHtml;
                    } else {
                        const cardId = form.dataset.logCard || '';
                        const card = cardId ? document.querySelector('[data-log-card="' + cardId + '"]') : null;
                        if (card) {
                            const sizeEl = card.querySelector('[data-log-size]');
                            const severityEl = card.querySelector('[data-log-severity]');
                            const stateEl = card.querySelector('[data-log-state]');
                            const indicatorEl = card.querySelector('[data-log-indicator]');
                            const previewEl = card.querySelector('[data-log-preview]');
                            if (sizeEl) sizeEl.textContent = '0 B';
                            if (severityEl) severityEl.textContent = 'INFO';
                            if (stateEl) stateEl.textContent = 'EMPTY';
                            if (indicatorEl) indicatorEl.className = 'indicator indicator-success';
                            if (previewEl) previewEl.innerHTML = emptyHtml;
                        }
                    }
                    setFlash((data.flash && data.flash.type) || 'success', (data.flash && data.flash.message) || 'Log cleared');
                } catch (error) {
                    setFlash('error', error instanceof Error ? error.message : 'Failed to clear log');
                } finally {
                    if (button) button.disabled = false;
                }
            });
        });
    })();
    </script>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
</body>
</html>
