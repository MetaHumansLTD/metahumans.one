<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

function mh_infra_cli_usage(): void {
    $self = basename(__FILE__);
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php {$self} --all\n");
    fwrite(STDERR, "  php {$self} --host=<hostname>\n");
    fwrite(STDERR, "  php {$self} --hosts=<h1,h2,...>\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --timeout=<seconds>   SSH connect timeout (default: 12)\n");
    fwrite(STDERR, "  --out=<dir>           Output root (default: ../drift/reports)\n");
    exit(2);
}

function mh_infra_exec_disabled(): bool {
    $disabled = (string)ini_get('disable_functions');
    if ($disabled !== '') {
        $parts = array_filter(array_map('trim', explode(',', $disabled)), static fn($v) => $v !== '');
        if (in_array('exec', $parts, true)) return true;
        if (in_array('shell_exec', $parts, true)) return true;
        if (in_array('proc_open', $parts, true)) return true;
    }
    if (!function_exists('proc_open')) return true;
    return false;
}

function mh_infra_is_local_host(string $host): bool {
    $h = strtolower(trim($host));
    return $h === 'local' || $h === 'localhost' || $h === '127.0.0.1' || $h === '::1';
}

function mh_infra_ssh_user_for_host(string $host): string {
    $h = strtolower(trim($host));
    if ($h === 'metahumans.one') return 'root';
    return 'mhadmin';
}

function mh_infra_ssh_keys_for_host(string $host): array {
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

function mh_infra_run_bash_script_local(string $script, int $timeoutSeconds): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open('bash -s', $descriptors, $pipes, '/');
    if (!is_resource($proc)) return ['ok' => false, 'exit' => 255, 'stdout' => '', 'stderr' => 'proc_open_failed'];
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], $timeoutSeconds);
    stream_set_timeout($pipes[2], $timeoutSeconds);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['ok' => ($exit === 0), 'exit' => $exit, 'stdout' => is_string($stdout) ? $stdout : '', 'stderr' => is_string($stderr) ? $stderr : ''];
}

function mh_infra_run_bash_script_ssh(string $host, string $user, string $sshKey, string $script, int $timeoutSeconds): array {
    $knownHostsFile = '/home/onemeta/.ssh/known_hosts_enterprise_monitor';
    $knownHostsDir = dirname($knownHostsFile);
    if (!is_dir($knownHostsDir)) @mkdir($knownHostsDir, 0700, true);
    if (!is_file($knownHostsFile)) {
        @file_put_contents($knownHostsFile, '', LOCK_EX);
        @chmod($knownHostsFile, 0600);
    }
    $cmd = sprintf(
        'ssh -i %s -o IdentitiesOnly=yes -o UserKnownHostsFile=%s -o GlobalKnownHostsFile=/dev/null -o StrictHostKeyChecking=accept-new -o ConnectTimeout=%d -o BatchMode=yes %s bash -s',
        escapeshellarg($sshKey),
        escapeshellarg($knownHostsFile),
        max(1, $timeoutSeconds),
        escapeshellarg($user . '@' . $host)
    );
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, '/');
    if (!is_resource($proc)) return ['ok' => false, 'exit' => 255, 'stdout' => '', 'stderr' => 'proc_open_failed'];
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    stream_set_timeout($pipes[1], $timeoutSeconds + 5);
    stream_set_timeout($pipes[2], $timeoutSeconds + 5);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['ok' => ($exit === 0), 'exit' => $exit, 'stdout' => is_string($stdout) ? $stdout : '', 'stderr' => is_string($stderr) ? $stderr : ''];
}

function mh_infra_run_bash_script_any(string $host, string $script, int $timeoutSeconds): array {
    if (mh_infra_is_local_host($host)) {
        return mh_infra_run_bash_script_local($script, $timeoutSeconds);
    }
    $user = mh_infra_ssh_user_for_host($host);
    $keys = mh_infra_ssh_keys_for_host($host);
    if (empty($keys)) return ['ok' => false, 'exit' => 255, 'stdout' => '', 'stderr' => 'ssh_key_not_readable'];
    $attempts = [];
    foreach ($keys as $k) {
        $res = mh_infra_run_bash_script_ssh($host, $user, $k, $script, $timeoutSeconds);
        $attempts[] = ['key' => $k, 'ok' => $res['ok'] ?? false, 'exit' => $res['exit'] ?? null];
        if (($res['ok'] ?? false) === true) {
            $res['attempts'] = $attempts;
            return $res;
        }
        if (is_string($res['stderr'] ?? null) && stripos((string)$res['stderr'], 'Permission denied') !== false) {
            $res['attempts'] = $attempts;
            return $res;
        }
    }
    $res['attempts'] = $attempts;
    return $res;
}

function mh_infra_parse_kv_lines(string $text): array {
    $out = [];
    $lines = preg_split('/\r?\n/', $text);
    if (!is_array($lines)) return $out;
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ($k === '') continue;
        $out[$k] = $v;
    }
    return $out;
}

function mh_infra_bytes_to_gb_int(?int $bytes): ?int {
    if (!is_int($bytes) || $bytes <= 0) return null;
    return (int)round($bytes / 1024 / 1024 / 1024);
}

function mh_infra_collect_one(string $host, string $collectorsDir, int $timeoutSeconds): array {
    $ts = gmdate('Y-m-d\\TH:i:s\\Z');

    $osScript = @file_get_contents($collectorsDir . '/linux_os.sh');
    $hwScript = @file_get_contents($collectorsDir . '/linux_hardware.sh');
    $gpuScript = @file_get_contents($collectorsDir . '/linux_gpu.sh');
    $storageScript = @file_get_contents($collectorsDir . '/linux_storage.sh');
    $netScript = @file_get_contents($collectorsDir . '/linux_network.sh');
    $portsScript = @file_get_contents($collectorsDir . '/linux_ports.sh');

    $missing = [];
    foreach ([
        'linux_os.sh' => $osScript,
        'linux_hardware.sh' => $hwScript,
        'linux_gpu.sh' => $gpuScript,
        'linux_storage.sh' => $storageScript,
        'linux_network.sh' => $netScript,
        'linux_ports.sh' => $portsScript,
    ] as $name => $content) {
        if (!is_string($content) || $content === '') $missing[] = $name;
    }
    if (!empty($missing)) {
        return ['ok' => false, 'error' => 'missing_collectors', 'missing' => $missing];
    }

    $osRes = mh_infra_run_bash_script_any($host, $osScript, $timeoutSeconds);
    if (!(($osRes['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'ssh_failed', 'stage' => 'os', 'detail' => $osRes];
    $os = mh_infra_parse_kv_lines((string)($osRes['stdout'] ?? ''));

    $hwRes = mh_infra_run_bash_script_any($host, $hwScript, $timeoutSeconds);
    if (!(($hwRes['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'ssh_failed', 'stage' => 'hardware', 'detail' => $hwRes];
    $hw = mh_infra_parse_kv_lines((string)($hwRes['stdout'] ?? ''));

    $gpuRes = mh_infra_run_bash_script_any($host, $gpuScript, $timeoutSeconds);
    if (!(($gpuRes['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'ssh_failed', 'stage' => 'gpu', 'detail' => $gpuRes];
    $gpuLines = preg_split('/\r?\n/', (string)($gpuRes['stdout'] ?? ''));
    $gpuLines = is_array($gpuLines) ? array_values(array_filter(array_map('trim', $gpuLines), static fn($v) => $v !== '')) : [];

    $storageRes = mh_infra_run_bash_script_any($host, $storageScript, $timeoutSeconds);
    if (!(($storageRes['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'ssh_failed', 'stage' => 'storage', 'detail' => $storageRes];
    $storageLines = preg_split('/\r?\n/', (string)($storageRes['stdout'] ?? ''));
    $storageLines = is_array($storageLines) ? array_values(array_filter(array_map('trim', $storageLines), static fn($v) => $v !== '')) : [];

    $netRes = mh_infra_run_bash_script_any($host, $netScript, $timeoutSeconds);
    if (!(($netRes['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'ssh_failed', 'stage' => 'network', 'detail' => $netRes];
    $netLines = preg_split('/\r?\n/', (string)($netRes['stdout'] ?? ''));
    $netLines = is_array($netLines) ? array_values(array_filter(array_map('trim', $netLines), static fn($v) => $v !== '')) : [];

    $portsRes = mh_infra_run_bash_script_any($host, $portsScript, $timeoutSeconds);
    if (!(($portsRes['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'ssh_failed', 'stage' => 'ports', 'detail' => $portsRes];
    $portsLines = preg_split('/\r?\n/', (string)($portsRes['stdout'] ?? ''));
    $portsLines = is_array($portsLines) ? array_values(array_filter(array_map('trim', $portsLines), static fn($v) => $v !== '')) : [];

    $cpu = [
        'vendor' => $hw['CPU_VENDOR'] ?? null,
        'model' => $hw['CPU_MODEL'] ?? null,
        'sockets' => isset($hw['CPU_SOCKETS']) ? (int)$hw['CPU_SOCKETS'] : null,
        'cores_per_socket' => isset($hw['CPU_CORES_PER_SOCKET']) ? (int)$hw['CPU_CORES_PER_SOCKET'] : null,
        'threads_total' => isset($hw['CPU_THREADS_TOTAL']) ? (int)$hw['CPU_THREADS_TOTAL'] : null,
    ];

    $ramKb = isset($hw['RAM_KB']) ? (int)$hw['RAM_KB'] : null;
    $ramGb = is_int($ramKb) && $ramKb > 0 ? (int)round(($ramKb * 1024) / 1024 / 1024 / 1024) : null;

    $gpu = [
        'vendor' => null,
        'model' => null,
        'count' => 0,
        'vram_gb_per_gpu' => null,
        'gpus' => [],
    ];
    foreach ($gpuLines as $line) {
        $parts = explode('|', $line);
        if (count($parts) < 4 || $parts[0] !== 'GPU') continue;
        $idx = (int)$parts[1];
        $name = (string)$parts[2];
        $vramMb = (int)$parts[3];
        $gpu['gpus'][] = ['index' => $idx, 'name' => $name, 'vram_mb' => $vramMb];
    }
    if (!empty($gpu['gpus'])) {
        $gpu['vendor'] = 'NVIDIA';
        $gpu['count'] = count($gpu['gpus']);
        $gpu['model'] = (string)($gpu['gpus'][0]['name'] ?? '');
        $gpu['vram_gb_per_gpu'] = (int)round(((int)($gpu['gpus'][0]['vram_mb'] ?? 0)) / 1024);
    }

    $storage = [];
    foreach ($storageLines as $line) {
        $parts = explode('|', $line);
        if (count($parts) < 6 || $parts[0] !== 'MOUNT') continue;
        $mount = (string)$parts[1];
        $fstype = (string)$parts[2];
        $device = (string)$parts[3];
        $sizeBytes = (int)$parts[4];
        $usedBytes = (int)$parts[5];
        $storage[] = [
            'mountpoint' => $mount,
            'fstype' => $fstype,
            'device' => $device,
            'size_gb' => mh_infra_bytes_to_gb_int($sizeBytes),
            'used_gb' => mh_infra_bytes_to_gb_int($usedBytes),
        ];
    }

    $network = ['addresses' => [], 'routes_v4' => [], 'routes_v6' => []];
    foreach ($netLines as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 2) continue;
        if ($parts[0] === 'ADDR4' || $parts[0] === 'ADDR6') {
            if (count($parts) < 3) continue;
            $ifname = (string)$parts[1];
            $cidr = (string)$parts[2];
            $network['addresses'][] = ['family' => $parts[0] === 'ADDR4' ? 4 : 6, 'interface' => $ifname, 'cidr' => $cidr];
            continue;
        }
        if ($parts[0] === 'ROUTE4') {
            $network['routes_v4'][] = (string)($parts[1] ?? '');
            continue;
        }
        if ($parts[0] === 'ROUTE6') {
            $network['routes_v6'][] = (string)($parts[1] ?? '');
            continue;
        }
    }
    $network['routes_v4'] = array_values(array_filter(array_map('trim', $network['routes_v4']), static fn($v) => $v !== ''));
    $network['routes_v6'] = array_values(array_filter(array_map('trim', $network['routes_v6']), static fn($v) => $v !== ''));

    $ports = ['listening' => []];
    foreach ($portsLines as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 3 || $parts[0] !== 'PORT') continue;
        $ports['listening'][] = ['proto' => (string)$parts[1], 'local' => (string)$parts[2]];
    }

    $snapshot = [
        'host' => $host,
        'observed_at' => $ts,
        'os' => [
            'pretty' => $os['OS_PRETTY'] ?? null,
            'kernel' => $os['KERNEL'] ?? null,
            'hostname' => $os['HOSTNAME'] ?? null,
            'uptime_sec' => isset($os['UPTIME_SEC']) ? (int)$os['UPTIME_SEC'] : null,
        ],
        'cpu' => $cpu,
        'ram_gb' => $ramGb,
        'gpu' => $gpu,
        'storage' => $storage,
        'network' => $network,
        'ports' => $ports,
    ];

    return ['ok' => true, 'snapshot' => $snapshot];
}

if (mh_infra_exec_disabled()) {
    fwrite(STDERR, "Error: proc_open/exec is disabled in this PHP environment.\n");
    exit(1);
}

$args = $argv;
array_shift($args);

$hosts = [];
$timeoutSeconds = 12;
$outDir = realpath(__DIR__ . '/../drift/reports') ?: (__DIR__ . '/../drift/reports');

foreach ($args as $a) {
    $a = (string)$a;
    if ($a === '--all') continue;
    if (strpos($a, '--host=') === 0) {
        $hosts[] = trim(substr($a, 7));
        continue;
    }
    if (strpos($a, '--hosts=') === 0) {
        $list = trim(substr($a, 8));
        foreach (explode(',', $list) as $h) {
            $h = trim($h);
            if ($h !== '') $hosts[] = $h;
        }
        continue;
    }
    if (strpos($a, '--timeout=') === 0) {
        $timeoutSeconds = (int)trim(substr($a, 10));
        if ($timeoutSeconds < 1) $timeoutSeconds = 1;
        if ($timeoutSeconds > 120) $timeoutSeconds = 120;
        continue;
    }
    if (strpos($a, '--out=') === 0) {
        $outDir = rtrim(trim(substr($a, 6)), '/');
        continue;
    }
    if ($a === '--help' || $a === '-h') mh_infra_cli_usage();
    mh_infra_cli_usage();
}

$hosts = array_values(array_filter(array_unique(array_map('trim', $hosts)), static fn($v) => is_string($v) && $v !== ''));
if (empty($hosts)) {
    $hosts = [
        'metahumans.one',
        'superhumans.one',
        'superbrains.one',
        'api.superhumans.one',
        'ingress.superhumans.one',
        'rke-cp-1.superhumans.one',
        'rke-cp-2.superhumans.one',
    ];
}

$collectorsDir = __DIR__ . '/collectors';
if (!is_dir($collectorsDir)) {
    fwrite(STDERR, "Error: collectors directory not found: {$collectorsDir}\n");
    exit(1);
}

if (!is_dir($outDir)) @mkdir($outDir, 0755, true);

$okCount = 0;
$failCount = 0;
foreach ($hosts as $host) {
    $res = mh_infra_collect_one($host, $collectorsDir, $timeoutSeconds);
    if (!(($res['ok'] ?? false) === true)) {
        $failCount++;
        fwrite(STDERR, "FAIL {$host} " . json_encode($res, JSON_UNESCAPED_SLASHES) . "\n");
        continue;
    }
    $snapshot = $res['snapshot'];
    $ts = gmdate('Ymd-His');
    $safeHost = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)$host);
    $dir = rtrim($outDir, '/') . '/' . $safeHost;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = $dir . '/' . $ts . '.json';
    $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $wrote = @file_put_contents($path, (string)$json, LOCK_EX);
    if ($wrote === false) {
        $failCount++;
        fwrite(STDERR, "FAIL {$host} write_failed {$path}\n");
        continue;
    }
    $okCount++;
    fwrite(STDOUT, "OK {$host} {$path}\n");
}

fwrite(STDOUT, "Summary: ok={$okCount} fail={$failCount}\n");
exit($failCount > 0 ? 1 : 0);

