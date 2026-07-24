<?php
declare(strict_types=1);

$isAjax = (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']));
if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);
if ($isAjax && !defined('CUE_DISABLE_PERFORMANCE_LOG')) define('CUE_DISABLE_PERFORMANCE_LOG', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
mh_kripz_require('ports', $isAjax);

function ports_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ports_csrf(): string {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $k = 'mh_ports_csrf';
    $t = isset($_SESSION[$k]) ? (string)$_SESSION[$k] : '';
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        $_SESSION[$k] = $t;
    }
    return $t;
}

function ports_require_csrf(): void {
    $t = ports_csrf();
    $p = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if ($p === '' || !hash_equals($t, $p)) {
        ports_json(['success' => false, 'error' => 'invalid_csrf'], 400);
    }
}

function ports_expected_ports(): array {
    return [
        'metahumans.one' => [
            ['service' => 'web (http)', 'port' => 80, 'proto' => 'tcp', 'expected_process' => ['nginx', 'httpd', 'apache2']],
            ['service' => 'web (https)', 'port' => 443, 'proto' => 'tcp', 'expected_process' => ['nginx', 'httpd', 'apache2']],
              ['service' => 'multica ui', 'port' => 8445, 'proto' => 'tcp', 'expected_process' => ['httpd', 'apache2']],
              ['service' => 'multica frontend', 'port' => 13000, 'proto' => 'tcp', 'expected_process' => ['docker-proxy'], 'bind_policy' => 'loopback_only', 'exposure' => 'internal'],
              ['service' => 'multica backend', 'port' => 18080, 'proto' => 'tcp', 'expected_process' => ['docker-proxy'], 'bind_policy' => 'loopback_only', 'exposure' => 'internal'],
            ['service' => 'ssh', 'port' => 22, 'proto' => 'tcp', 'expected_process' => ['sshd']],
            ['service' => 'ftp', 'port' => 21, 'proto' => 'tcp', 'expected_process' => ['pure-ftpd', 'proftpd', 'vsftpd']],
            ['service' => 'dns', 'port' => 53, 'proto' => 'tcp', 'expected_process' => ['pdns_server']],
            ['service' => 'dns (api)', 'port' => 953, 'proto' => 'tcp', 'expected_process' => ['pdns_server'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'smtp', 'port' => 25, 'proto' => 'tcp', 'expected_process' => ['exim', 'postfix']],
            ['service' => 'smtp (submission)', 'port' => 587, 'proto' => 'tcp', 'expected_process' => ['exim', 'postfix']],
            ['service' => 'smtps', 'port' => 465, 'proto' => 'tcp', 'expected_process' => ['exim', 'postfix']],
            ['service' => 'imap', 'port' => 143, 'proto' => 'tcp', 'expected_process' => ['dovecot']],
            ['service' => 'imaps', 'port' => 993, 'proto' => 'tcp', 'expected_process' => ['dovecot']],
            ['service' => 'pop3', 'port' => 110, 'proto' => 'tcp', 'expected_process' => ['dovecot']],
            ['service' => 'pop3s', 'port' => 995, 'proto' => 'tcp', 'expected_process' => ['dovecot']],
            ['service' => 'webdisk (ssl)', 'port' => 2077, 'proto' => 'tcp', 'expected_process' => ['cpdavd']],
            ['service' => 'webdisk', 'port' => 2078, 'proto' => 'tcp', 'expected_process' => ['cpdavd']],
            ['service' => 'webdisk (alt)', 'port' => 2079, 'proto' => 'tcp', 'expected_process' => ['cpdavd']],
            ['service' => 'webdav', 'port' => 2080, 'proto' => 'tcp', 'expected_process' => ['cpdavd']],
            ['service' => 'cpanel', 'port' => 2083, 'proto' => 'tcp', 'expected_process' => ['cpsrvd', 'cpaneld', 'sw-engine']],
            ['service' => 'cpanel (http)', 'port' => 2082, 'proto' => 'tcp', 'expected_process' => ['cpsrvd', 'cpaneld', 'sw-engine']],
            ['service' => 'whm', 'port' => 2087, 'proto' => 'tcp', 'expected_process' => ['cpsrvd', 'whostmgrd', 'sw-engine']],
            ['service' => 'whm (http)', 'port' => 2086, 'proto' => 'tcp', 'expected_process' => ['cpsrvd', 'whostmgrd', 'sw-engine']],
            ['service' => 'webmail (alt)', 'port' => 2091, 'proto' => 'tcp', 'expected_process' => ['cpdavd', 'cpsrvd', 'webmaild', 'sw-engine']],
            ['service' => 'webmail', 'port' => 2096, 'proto' => 'tcp', 'expected_process' => ['cpsrvd', 'webmaild', 'sw-engine']],
            ['service' => 'webmail (http)', 'port' => 2095, 'proto' => 'tcp', 'expected_process' => ['cpsrvd', 'webmaild', 'sw-engine']],
            ['service' => 'redis', 'port' => 6379, 'proto' => 'tcp', 'expected_process' => ['redis-server'], 'bind_policy' => 'loopback_only'],
            ['service' => 'nats', 'port' => 4222, 'proto' => 'tcp', 'expected_process' => ['nats-server', 'nats'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'nats (http)', 'port' => 8222, 'proto' => 'tcp', 'expected_process' => ['nats-server', 'nats'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'nats (cluster)', 'port' => 8223, 'proto' => 'tcp', 'expected_process' => ['nats-server', 'nats'], 'bind_policy' => 'loopback_or_private'],

            ['service' => 'plugnmeet', 'port' => 8090, 'proto' => 'tcp', 'expected_process' => ['plugnmeet-serve', 'conmon'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'mariadb (whm)', 'port' => 3306, 'proto' => 'tcp', 'expected_process' => ['mariadbd', 'mysqld'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'mariadb (block)', 'port' => 3307, 'proto' => 'tcp', 'expected_process' => ['mariadbd', 'mysqld'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'qdrant', 'port' => 6333, 'proto' => 'tcp', 'expected_process' => ['qdrant'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'qdrant (grpc)', 'port' => 6334, 'proto' => 'tcp', 'expected_process' => ['qdrant'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'neo4j (http)', 'port' => 7474, 'proto' => 'tcp', 'expected_process' => ['java', 'neo4j'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'neo4j (bolt)', 'port' => 7687, 'proto' => 'tcp', 'expected_process' => ['java', 'neo4j'], 'bind_policy' => 'loopback_or_private'],
            ['service' => 'g0dm0d3 (https)', 'port' => 3010, 'proto' => 'tcp', 'expected_process' => ['httpd', 'apache2'], 'exposure' => 'public'],
            ['service' => 'g0dm0d3 api', 'port' => 7860, 'proto' => 'tcp', 'expected_process' => ['node', 'tsx'], 'bind_policy' => 'loopback_or_private', 'exposure' => 'internal'],
        ],
        'superhumans.one' => [
            ['service' => 'nginx (http)', 'port' => 80, 'proto' => 'tcp', 'expected_process' => ['nginx']],
            ['service' => 'nginx (https)', 'port' => 443, 'proto' => 'tcp', 'expected_process' => ['nginx']],
            ['service' => 'ollama', 'port' => 11434, 'proto' => 'tcp', 'expected_process' => ['ollama'], 'exposure' => 'internal'],
            ['service' => 'tock', 'port' => 8000, 'proto' => 'tcp', 'expected_process' => ['python', 'uvicorn', 'tock'], 'exposure' => 'internal'],
            ['service' => 'agentic-stack (mission-control)', 'port' => 3132, 'proto' => 'tcp', 'expected_process' => ['python', 'uvicorn', 'harness_manager', 'mission-control'], 'bind_policy' => 'loopback_or_private', 'exposure' => 'internal'],
            ['service' => 'plugnmeet (client)', 'port' => 8081, 'proto' => 'tcp', 'expected_process' => ['nginx', 'plugnmeet'], 'exposure' => 'internal'],
            ['service' => 'livekit (ws/http)', 'port' => 7880, 'proto' => 'tcp', 'expected_process' => ['livekit-server'], 'exposure' => 'public'],
            ['service' => 'livekit (rtc/tcp)', 'port' => 7881, 'proto' => 'tcp', 'expected_process' => ['livekit-server'], 'exposure' => 'public'],
            ['service' => 'livekit (rtc/udp)', 'port' => 7882, 'proto' => 'udp', 'expected_process' => ['livekit-server'], 'exposure' => 'public'],
            ['service' => 'nats', 'port' => 9222, 'proto' => 'tcp', 'expected_process' => ['nats-server', 'nats'], 'exposure' => 'internal'],
            ['service' => 'hermes (v1)', 'port' => 30405, 'proto' => 'tcp', 'expected_process' => ['llm', 'hermes', 'python', 'uvicorn'], 'exposure' => 'internal'],
        ],
    ];
}

function ports_exposure(array $row): string {
    $exp = isset($row['exposure']) && is_string($row['exposure']) ? strtolower(trim((string)$row['exposure'])) : '';
    if ($exp === 'public' || $exp === 'internal') return $exp;
    $policy = isset($row['bind_policy']) && is_string($row['bind_policy']) ? trim((string)$row['bind_policy']) : '';
    if ($policy !== '') return 'internal';
    return 'public';
}

function ports_domain_list(): array {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $domains = isset($_SESSION['mh_ports_domains']) && is_array($_SESSION['mh_ports_domains']) ? $_SESSION['mh_ports_domains'] : [];
    if ($domains === []) {
        $domains = ['metahumans.one', 'superhumans.one'];
        $_SESSION['mh_ports_domains'] = $domains;
    }
    $out = [];
    foreach ($domains as $d) {
        if (!is_string($d)) continue;
        $d = strtolower(trim($d));
        if ($d === '') continue;
        $out[] = $d;
    }
    $out = array_values(array_unique($out));
    $_SESSION['mh_ports_domains'] = $out;
    return $out;
}

function ports_sanitize_domain(string $domain): string {
    $domain = strtolower(trim($domain));
    $domain = preg_replace('/[^a-z0-9\\-\\.]+/', '', $domain);
    $domain = trim((string)$domain, '.');
    if ($domain === '' || strlen($domain) > 190) return '';
    if (strpos($domain, '.') === false) return '';
    return $domain;
}

function ports_tcp_check(string $host, int $port, float $timeout = 0.35): bool {
    $errNo = 0;
    $errStr = '';
    $fp = @fsockopen($host, $port, $errNo, $errStr, $timeout);
    if (is_resource($fp)) {
        fclose($fp);
        return true;
    }
    return false;
}

function ports_local_listening_ports(): array {
    $tcpFiles = ['/proc/net/tcp', '/proc/net/tcp6'];
    $listeners = [];

    $decodeIpv4 = function (string $hex): string {
        $hex = strtolower($hex);
        if (strlen($hex) !== 8 || !ctype_xdigit($hex)) return $hex;
        $bytes = str_split($hex, 2);
        $bytes = array_reverse($bytes);
        $parts = [];
        foreach ($bytes as $b) $parts[] = (string)hexdec($b);
        return implode('.', $parts);
    };

    $decodeIpv6 = function (string $hex): string {
        $hex = strtolower($hex);
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) return $hex;
        if ($hex === str_repeat('0', 32)) return '::';
        if ($hex === str_repeat('0', 30) . '0001') return '::1';
        $chunks = str_split($hex, 4);
        $chunks = array_map(function ($c) {
            $c = ltrim($c, '0');
            return $c === '' ? '0' : $c;
        }, $chunks);
        return implode(':', $chunks);
    };

    foreach ($tcpFiles as $file) {
        if (!is_file($file) || !is_readable($file)) continue;
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || count($lines) < 2) continue;
        foreach ($lines as $idx => $line) {
            if ($idx === 0) continue;
            $line = trim((string)$line);
            if ($line === '') continue;
            $cols = preg_split('/\\s+/', $line);
            if (!is_array($cols) || count($cols) < 10) continue;
            $local = (string)($cols[1] ?? '');
            $state = (string)($cols[3] ?? '');
            $inode = (string)($cols[9] ?? '');
            if ($state !== '0A') continue;
            if ($local === '' || $inode === '' || !ctype_digit($inode)) continue;
            if (!preg_match('/^([0-9A-Fa-f]+):([0-9A-Fa-f]{4})$/', $local, $m)) continue;
            $addrHex = (string)$m[1];
            $port = hexdec((string)$m[2]);
            if (!is_int($port) || $port <= 0) continue;
            $ip = (strlen($addrHex) === 8) ? $decodeIpv4($addrHex) : $decodeIpv6($addrHex);
            $bind = (strlen($addrHex) === 32) ? ('[' . $ip . ']:' . $port) : ($ip . ':' . $port);
            $listeners[] = [
                'port' => (int)$port,
                'local' => $bind,
                'inode' => (int)$inode,
            ];
        }
    }

    if ($listeners === []) return [];

    $need = [];
    foreach ($listeners as $l) $need[(int)$l['inode']] = true;

    $inodeToProc = [];
    $remaining = count($need);
    foreach (new DirectoryIterator('/proc') as $fi) {
        if (!$fi->isDir()) continue;
        $pidStr = $fi->getFilename();
        if (!ctype_digit($pidStr)) continue;
        $pid = (int)$pidStr;
        $fdDir = '/proc/' . $pidStr . '/fd';
        if (!is_dir($fdDir) || !is_readable($fdDir)) continue;
        $commPath = '/proc/' . $pidStr . '/comm';
        $procName = '';
        if (is_file($commPath)) {
            $procName = trim((string)@file_get_contents($commPath));
        }
        foreach (@scandir($fdDir) ?: [] as $fd) {
            if ($fd === '.' || $fd === '..') continue;
            $target = @readlink($fdDir . '/' . $fd);
            if (!is_string($target)) continue;
            if (!preg_match('/socket:\\[(\\d+)\\]/', $target, $m)) continue;
            $inode = (int)$m[1];
            if (!isset($need[$inode])) continue;
            if (!isset($inodeToProc[$inode])) {
                $inodeToProc[$inode] = ['pid' => $pid, 'process' => $procName !== '' ? $procName : null];
                $remaining--;
                if ($remaining <= 0) break 2;
            }
        }
    }

    $rows = [];
    foreach ($listeners as $l) {
        $inode = (int)$l['inode'];
        $p = $inodeToProc[$inode] ?? null;
        $rows[] = [
            'port' => (int)$l['port'],
            'local' => (string)$l['local'],
            'process' => is_array($p) && isset($p['process']) ? (string)($p['process'] ?? '') : '',
            'pid' => is_array($p) && isset($p['pid']) ? (int)($p['pid'] ?? 0) : null,
        ];
    }

    usort($rows, fn($a, $b) => ((int)$a['port']) <=> ((int)$b['port']));
    return $rows;
}

function ports_analyze_conflicts(array $localPorts, array $expectedByHost): array {
    $expectedMap = [];
    foreach ($expectedByHost as $host => $list) {
        foreach ($list as $row) {
            $p = (int)($row['port'] ?? 0);
            if ($p <= 0) continue;
            $expectedMap[$p] = $expectedMap[$p] ?? [];
            $expectedMap[$p][] = [
                'host' => (string)$host,
                'service' => (string)($row['service'] ?? ''),
                'expected_process' => is_array($row['expected_process'] ?? null) ? $row['expected_process'] : [],
                'bind_policy' => is_string($row['bind_policy'] ?? null) ? (string)$row['bind_policy'] : '',
            ];
        }
    }

    $localByPort = [];
    foreach ($localPorts as $r) {
        $p = (int)($r['port'] ?? 0);
        if ($p <= 0) continue;
        $localByPort[$p] = $r;
    }

    $issues = [];
    foreach ($expectedMap as $port => $services) {
        $binding = $localByPort[$port] ?? null;
        if (!$binding) {
            $issues[] = ['type' => 'missing_expected_port', 'port' => $port, 'expected' => $services];
            continue;
        }
        $proc = strtolower((string)($binding['process'] ?? ''));
        foreach ($services as $svc) {
            $allowed = array_map('strtolower', (array)($svc['expected_process'] ?? []));
            if ($allowed !== [] && $proc !== '' && !in_array($proc, $allowed, true)) {
                $issues[] = [
                    'type' => 'unexpected_process',
                    'port' => $port,
                    'expected' => $svc,
                    'actual' => ['process' => $binding['process'] ?? '', 'pid' => $binding['pid'] ?? null, 'local' => $binding['local'] ?? ''],
                ];
            }

            $policy = is_string($svc['bind_policy'] ?? null) ? (string)$svc['bind_policy'] : '';
            if ($policy !== '') {
                $local = is_string($binding['local'] ?? null) ? (string)$binding['local'] : '';
                $host = preg_replace('/:\\d+$/', '', $local);
                $host = trim((string)$host, '[]');
                $isLoopback = ($host === '127.0.0.1' || $host === '::1');
                $isPrivateV4 = (bool)preg_match('/^(10\\.|192\\.168\\.|172\\.(1[6-9]|2\\d|3[0-1])\\.)/', $host);
                $isPrivateV6 = (bool)preg_match('/^(fc|fd)[0-9a-f]{2}:/i', $host);
                $isWildcard = ($host === '0.0.0.0' || $host === '::' || $host === '*');
                $ok = false;
                if ($policy === 'loopback_only') {
                    $ok = $isLoopback;
                } elseif ($policy === 'loopback_or_private') {
                    $ok = $isLoopback || $isPrivateV4 || $isPrivateV6;
                }
                if (!$ok && ($isWildcard || $host !== '')) {
                    $issues[] = [
                        'type' => 'broad_bind',
                        'port' => $port,
                        'expected' => $svc,
                        'actual' => ['local' => $local, 'process' => $binding['process'] ?? '', 'pid' => $binding['pid'] ?? null],
                    ];
                }
            }
        }
        if (count($services) > 1) {
            $issues[] = ['type' => 'shared_port', 'port' => $port, 'services' => $services];
        }
    }

    foreach ($localByPort as $port => $binding) {
        if (!isset($expectedMap[$port])) {
            $issues[] = ['type' => 'other_open_port', 'port' => $port, 'actual' => $binding];
        }
    }
    return $issues;
}

if ($isAjax) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    ports_require_csrf();

    if ($action === 'get_state') {
        ports_json(['success' => true, 'domains' => ports_domain_list(), 'expected' => ports_expected_ports()]);
    }
    if ($action === 'get_local_ports') {
        $expected = ports_expected_ports();
        $local = ports_local_listening_ports();
        $issues = ports_analyze_conflicts($local, $expected);
        ports_json(['success' => true, 'local' => $local, 'issues' => $issues]);
    }
    if ($action === 'add_domain') {
        $d = isset($_POST['domain']) ? (string)$_POST['domain'] : '';
        $d = ports_sanitize_domain($d);
        if ($d === '') ports_json(['success' => false, 'error' => 'invalid_domain'], 400);
        $domains = ports_domain_list();
        if (!in_array($d, $domains, true)) {
            $domains[] = $d;
        }
        $_SESSION['mh_ports_domains'] = array_slice(array_values(array_unique($domains)), 0, 12);
        ports_json(['success' => true, 'domains' => $_SESSION['mh_ports_domains']]);
    }
    if ($action === 'remove_domain') {
        $d = isset($_POST['domain']) ? (string)$_POST['domain'] : '';
        $d = ports_sanitize_domain($d);
        $domains = array_values(array_filter(ports_domain_list(), fn($x) => $x !== $d));
        $_SESSION['mh_ports_domains'] = $domains;
        ports_json(['success' => true, 'domains' => $domains]);
    }
    if ($action === 'scan_domain') {
        $d = isset($_POST['domain']) ? (string)$_POST['domain'] : '';
        $d = ports_sanitize_domain($d);
        if ($d === '') ports_json(['success' => false, 'error' => 'invalid_domain'], 400);
        $expected = ports_expected_ports();
        $scanList = [];
        foreach ($expected as $host => $rows) {
            if ($host === $d) {
                foreach ($rows as $r) {
                    $p = (int)($r['port'] ?? 0);
                    if ($p > 0) $scanList[] = $p;
                }
            }
        }
        $scanList = array_values(array_unique($scanList));
        if (count($scanList) < 1) {
            $scanList = [80, 443];
        }
        if (count($scanList) > 120) {
            $scanList = array_slice($scanList, 0, 120);
        }
        $results = [];
        $ip = gethostbyname($d);
        $hostForConnect = $d;
        foreach ($scanList as $p) {
            $open = ports_tcp_check($hostForConnect, (int)$p, 0.35);
            $results[] = ['port' => (int)$p, 'open' => $open];
        }
        ports_json(['success' => true, 'domain' => $d, 'ip' => $ip, 'results' => $results]);
    }

    ports_json(['success' => false, 'error' => 'invalid_action'], 400);
}

$csrf = ports_csrf();
$expected = ports_expected_ports();
$domains = ports_domain_list();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Ports Verification</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 16px; }
        .mh-row { display:flex; gap:12px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
        .mh-input { min-width:280px; padding:10px 12px; border-radius:10px; border:1px solid rgba(0,212,255,0.25); background:rgba(0,0,0,0.35); color:#fff; }
        .mh-btn { padding:10px 14px; border-radius:10px; border:1px solid rgba(0,212,255,0.25); background:#0aa0b6; color:#fff; cursor:pointer; font-weight:700; }
        .mh-btn-danger { border:1px solid rgba(255,120,120,0.35); background:rgba(255,120,120,0.12); color:#ffdede; }
        .mh-table { width:100%; border-collapse:collapse; }
        .mh-table th, .mh-table td { text-align:left; padding:8px; border-bottom:1px solid rgba(255,255,255,0.08); vertical-align:top; }
        .mh-badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid rgba(255,255,255,0.14); opacity:0.95; }
        .mh-badge-ok { background: rgba(16,185,129,0.16); border-color: rgba(16,185,129,0.35); color:#c8ffe8; }
        .mh-badge-bad { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.35); color:#ffd0d0; }
        .mh-badge-warn { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.35); color:#ffe7bf; }
        .mh-badge-info { background: rgba(0,212,255,0.12); border-color: rgba(0,212,255,0.35); color:#ccf9ff; }
        .mh-badge-internal { background: rgba(168,85,247,0.12); border-color: rgba(168,85,247,0.35); color:#f0dbff; }
        .mh-muted { opacity:0.8; }
        .cue-global-footer { position: static !important; left: auto !important; right: auto !important; bottom: auto !important; }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <section style="padding: 30px 0;">
        <div class="container" style="max-width: 1200px;">
            <h1 style="margin:0 0 8px 0;">Ports Verification</h1>
            <div class="mh-muted" style="margin-bottom:16px;">KripzMasters only. Validates local listeners and remote reachability for known services.</div>

            <div class="mh-card" style="border-color: rgba(255,255,255,0.08);margin-bottom:16px;">
                <div style="font-weight:700;margin-bottom:6px;">Triton / NodePort notes</div>
                <div class="mh-muted">Current NodePorts in the <code>triton</code> namespace are for vLLM/model-gateway/NAT services (not a <code>tritonserver</code> NodePort). Examples:</div>
                <ul style="margin:10px 0 0 18px;">
                    <li><code>hermes4-405b-api</code> &rarr; <code>30405</code></li>
                    <li><code>meta-humans-model-gateway</code> &rarr; <code>32140</code></li>
                    <li><code>qwen3-vl-30b-a3b-vision</code> &rarr; <code>30291</code></li>
                    <li><code>reka-edge-vision</code> &rarr; <code>30292</code></li>
                    <li><code>codestral-22b</code> &rarr; <code>30293</code></li>
                    <li><code>hypernova-60b</code> &rarr; <code>30294</code></li>
                    <li><code>nemo-agent-toolkit</code> &rarr; <code>30270</code></li>
                </ul>
            </div>

            <div class="mh-card" style="border-color: rgba(255,255,255,0.08);margin-bottom:16px;">
                <div style="font-weight:700;margin-bottom:6px;">Control Interfaces</div>
                <div class="mh-muted">Public control interfaces on <code>meta.superhumans.one</code>:</div>
                <div style="margin-top:10px;overflow:auto;">
                    <table class="mh-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Docs</th>
                                <th>OpenAPI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Hermes</td>
                                <td><a href="https://meta.superhumans.one/hermes/docs" target="_blank" rel="noopener">/hermes/docs</a></td>
                                <td><a href="https://meta.superhumans.one/hermes/openapi.json" target="_blank" rel="noopener">/hermes/openapi.json</a></td>
                            </tr>
                            <tr>
                                <td>Tock</td>
                                <td><a href="https://meta.superhumans.one/tock/docs" target="_blank" rel="noopener">/tock/docs</a></td>
                                <td><a href="https://meta.superhumans.one/tock/openapi.json" target="_blank" rel="noopener">/tock/openapi.json</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr;gap:16px;">
                <div class="mh-card">
                    <div class="mh-row">
                        <div style="font-weight:700;">Domains</div>
                        <div class="mh-row" style="justify-content:flex-end;">
                            <input id="mhPortsDomainInput" class="mh-input" type="text" placeholder="Add domain (e.g. meta.superhumans.one)" />
                            <button id="mhPortsAddDomainBtn" class="mh-btn" type="button">Add Domain</button>
                        </div>
                    </div>

                    <div style="margin-top:12px;display:grid;grid-template-columns:1fr;gap:10px;">
                        <?php foreach ($domains as $d): ?>
                            <?php $rows = $expected[$d] ?? null; ?>
                            <div class="mh-card" style="border-color: rgba(255,255,255,0.08);">
                                <div class="mh-row">
                                    <div style="font-weight:700;"><?php echo htmlspecialchars($d, ENT_QUOTES); ?></div>
                                    <div class="mh-row" style="justify-content:flex-end;">
                                        <button class="mh-btn mhPortsScanBtn" data-domain="<?php echo htmlspecialchars($d, ENT_QUOTES); ?>" type="button">Scan Reachability</button>
                                        <button class="mh-btn mh-btn-danger mhPortsRemoveDomainBtn" data-domain="<?php echo htmlspecialchars($d, ENT_QUOTES); ?>" type="button">Remove</button>
                                    </div>
                                </div>

                                <?php if (is_array($rows)): ?>
                                    <div style="margin-top:10px;overflow:auto;">
                                        <table class="mh-table">
                                            <thead>
                                                <tr>
                                                    <th>Service</th>
                                                    <th>Exposure</th>
                                                    <th>Proto</th>
                                                    <th>Port</th>
                                                    <th>Expected Process</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($rows as $r): ?>
                                                <?php $exposure = ports_exposure($r); ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars((string)$r['service'], ENT_QUOTES); ?></td>
                                                    <td>
                                                        <?php if ($exposure === 'internal'): ?>
                                                            <span class="mh-badge mh-badge-internal">Internal</span>
                                                        <?php else: ?>
                                                            <span class="mh-badge mh-badge-info">Public</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars((string)$r['proto'], ENT_QUOTES); ?></td>
                                                    <td><?php echo (int)$r['port']; ?></td>
                                                    <td><?php echo htmlspecialchars(implode(', ', (array)$r['expected_process']), ENT_QUOTES); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="mh-muted" style="margin-top:10px;">No expected port profile for this domain. Scan checks 80/443 by default.</div>
                                <?php endif; ?>

                                <div class="mhPortsScanOut" data-domain="<?php echo htmlspecialchars($d, ENT_QUOTES); ?>" style="margin-top:10px;display:none;">
                                    <div style="font-weight:700;margin-bottom:8px;">Scan Results</div>
                                    <div style="overflow:auto;">
                                        <table class="mh-table">
                                            <thead>
                                                <tr>
                                                    <th>Port</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody class="mhPortsScanRows"></tbody>
                                        </table>
                                    </div>
                                    <div class="mhPortsScanMeta mh-muted" style="margin-top:8px;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mh-card">
                    <div class="mh-row">
                        <div style="font-weight:700;">Local Listening Ports (metahumans.one)</div>
                        <button id="mhPortsLocalBtn" class="mh-btn" type="button">Refresh</button>
                    </div>

                    <div id="mhPortsLocalStatus" class="mh-muted" style="margin-top:10px;"></div>
                    <div style="margin-top:10px;overflow:auto;">
                        <table class="mh-table">
                            <thead>
                                <tr>
                                    <th>Port</th>
                                    <th>Bind</th>
                                    <th>Process</th>
                                    <th>PID</th>
                                </tr>
                            </thead>
                            <tbody id="mhPortsLocalRows"></tbody>
                        </table>
                    </div>

                    <div style="margin-top:14px;font-weight:700;">Conflicts / Issues</div>
                    <div style="margin-top:10px;overflow:auto;">
                        <table class="mh-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Port</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody id="mhPortsIssuesRows"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>
<?php
if (function_exists('renderGlobalFooter')) {
    renderGlobalFooter(['ftr_position' => 'bottom', 'ftr_auto_offset' => false]);
}
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (strpos($uri, '/pdf-tools') !== 0) {
    if (function_exists('renderGlobalWidgets')) {
        renderGlobalWidgets();
    } elseif (function_exists('renderGlobalStatusBar')) {
        renderGlobalStatusBar();
    }
}
if (function_exists('includeGlobalUIScripts')) {
    includeGlobalUIScripts();
}
?>
<script>
(() => {
  const csrf = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;
  const post = async (data) => {
    const body = new URLSearchParams({ ...data, csrf });
    const resp = await fetch('', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
    const text = await resp.text();
    try {
      return { ok: resp.ok, json: JSON.parse(text) };
    } catch (e) {
      return { ok: false, json: { success: false, error: 'invalid_json', raw: text.slice(0, 500) } };
    }
  };

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const badge = (ok, labelOk = 'open', labelBad = 'closed') => ok
    ? '<span class="mh-badge mh-badge-ok">' + esc(labelOk) + '</span>'
    : '<span class="mh-badge mh-badge-bad">' + esc(labelBad) + '</span>';

  const localBtn = document.getElementById('mhPortsLocalBtn');
  const localRows = document.getElementById('mhPortsLocalRows');
  const issuesRows = document.getElementById('mhPortsIssuesRows');
  const localStatus = document.getElementById('mhPortsLocalStatus');

  const renderLocal = (data) => {
    if (!localRows || !issuesRows || !localStatus) return;
    localRows.innerHTML = '';
    issuesRows.innerHTML = '';
    if (!data || data.success !== true) {
      localStatus.textContent = 'Failed to load: ' + (data && data.error ? data.error : 'unknown');
      return;
    }
    const list = Array.isArray(data.local) ? data.local : [];
    const issues = Array.isArray(data.issues) ? data.issues : [];
    localStatus.textContent = list.length ? ('Found ' + list.length + ' listening TCP ports') : 'No listening ports detected (insufficient permissions or restricted environment)';

    if (list.length === 0) {
      localRows.innerHTML = '<tr><td colspan="4" class="mh-muted">No data</td></tr>';
    } else {
      localRows.innerHTML = list.map((r) => {
        const pid = (r.pid === null || r.pid === 0) ? '' : String(r.pid);
        return '<tr>' +
          '<td>' + esc(r.port) + '</td>' +
          '<td>' + esc(r.local) + '</td>' +
          '<td>' + esc(r.process) + '</td>' +
          '<td>' + esc(pid) + '</td>' +
        '</tr>';
      }).join('');
    }

    if (issues.length === 0) {
      issuesRows.innerHTML = '<tr><td colspan="3" class="mh-muted">No issues detected</td></tr>';
    } else {
      issuesRows.innerHTML = issues.map((it) => {
        const t = String(it.type || 'issue');
        const p = it.port ?? '';
        let details = '';
        if (t === 'missing_expected_port') details = 'Expected service not listening';
        else if (t === 'broad_bind') details = 'Sensitive service is bound to a non-private address (should be loopback/private)';
        else if (t === 'other_open_port') {
          const proc = it.actual && it.actual.process ? it.actual.process : '';
          const bind = it.actual && it.actual.local ? it.actual.local : '';
          details = 'Open port not in expected list';
          if (proc || bind) details += ' (bind: ' + String(bind || 'n/a') + ', proc: ' + String(proc || 'n/a') + ')';
        }
        else if (t === 'unexpected_process') details = 'Expected ' + esc((it.expected && it.expected.expected_process ? it.expected.expected_process.join(', ') : '')) + ', got ' + esc(it.actual && it.actual.process ? it.actual.process : '');
        else if (t === 'shared_port') details = 'Multiple expected services share this port';
        return '<tr>' +
          '<td><span class="mh-badge mh-badge-warn">' + esc(t) + '</span></td>' +
          '<td>' + esc(p) + '</td>' +
          '<td>' + esc(details) + '</td>' +
        '</tr>';
      }).join('');
    }
  };

  if (localBtn) {
    localBtn.addEventListener('click', async () => {
      if (localStatus) localStatus.textContent = 'Loading...';
      const r = await post({ action: 'get_local_ports' });
      renderLocal(r.json);
    });
    localBtn.click();
  }

  document.querySelectorAll('.mhPortsScanBtn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const domain = btn.getAttribute('data-domain') || '';
      const wrap = document.querySelector('.mhPortsScanOut[data-domain="' + domain.replace(/"/g,'') + '"]');
      if (!wrap) return;
      wrap.style.display = 'block';
      const tbody = wrap.querySelector('.mhPortsScanRows');
      const meta = wrap.querySelector('.mhPortsScanMeta');
      if (tbody) tbody.innerHTML = '<tr><td colspan="2" class="mh-muted">Scanning...</td></tr>';
      if (meta) meta.textContent = '';
      const r = await post({ action: 'scan_domain', domain });
      if (!r.json || r.json.success !== true) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="2" class="mh-muted">Failed to scan</td></tr>';
        return;
      }
      const results = Array.isArray(r.json.results) ? r.json.results : [];
      if (meta) meta.textContent = 'IP: ' + (r.json.ip || '') + ' | Checked: ' + results.length + ' ports';
      if (tbody) tbody.innerHTML = results.map((x) => {
        return '<tr><td>' + esc(x.port) + '</td><td>' + badge(!!x.open) + '</td></tr>';
      }).join('') || '<tr><td colspan="2" class="mh-muted">No results</td></tr>';
    });
  });

  document.querySelectorAll('.mhPortsRemoveDomainBtn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const domain = btn.getAttribute('data-domain') || '';
      await post({ action: 'remove_domain', domain });
      location.reload();
    });
  });

  const addBtn = document.getElementById('mhPortsAddDomainBtn');
  const addInput = document.getElementById('mhPortsDomainInput');
  if (addBtn && addInput) {
    addBtn.addEventListener('click', async () => {
      const domain = addInput.value || '';
      const r = await post({ action: 'add_domain', domain });
      if (r.json && r.json.success) {
        location.reload();
      } else {
        addInput.focus();
      }
    });
  }
})();
</script>
</body>
</html>
