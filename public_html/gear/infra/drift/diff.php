<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../netbox/bootstrap.php';

function mh_drift_usage(): void {
    $self = basename(__FILE__);
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php {$self} --reports=<dir>\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --out=<dir>       Output dir (default: <reports>/../drift-reports)\n");
    fwrite(STDERR, "  --hosts=<h1,h2>   Restrict to hosts\n");
    fwrite(STDERR, "  --mapping=<php>   Mapping file (default: ../netbox/mapping.php)\n");
    fwrite(STDERR, "  --format=json|text (default: json)\n");
    fwrite(STDERR, "Optional env for observed-vs-NetBox SoT:\n");
    fwrite(STDERR, "  NETBOX_URL\n");
    fwrite(STDERR, "  NETBOX_TOKEN\n");
    exit(2);
}

function mh_drift_list_host_dirs(string $reportsDir): array {
    $items = @scandir($reportsDir);
    if (!is_array($items)) return [];
    $hosts = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $reportsDir . '/' . $name;
        if (is_dir($path)) $hosts[] = $name;
    }
    sort($hosts);
    return $hosts;
}

function mh_drift_list_json_files(string $hostDir): array {
    $items = @scandir($hostDir);
    if (!is_array($items)) return [];
    $files = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        if (substr($name, -5) !== '.json') continue;
        $path = $hostDir . '/' . $name;
        if (is_file($path)) $files[] = $path;
    }
    sort($files);
    return $files;
}

function mh_drift_read_json(string $path): array {
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return ['ok' => false, 'error' => 'read_failed'];
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'json_decode_failed'];
    return ['ok' => true, 'data' => $data];
}

function mh_drift_norm_scalar(mixed $v): mixed {
    if (is_string($v)) return trim($v);
    if (is_int($v) || is_float($v) || is_bool($v) || $v === null) return $v;
    return json_encode($v, JSON_UNESCAPED_SLASHES);
}

function mh_drift_pick(array $snap, array $path, mixed $default = null): mixed {
    $cur = $snap;
    foreach ($path as $k) {
        if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
        $cur = $cur[$k];
    }
    return $cur;
}

function mh_drift_set_from_list(array $list): array {
    $out = [];
    foreach ($list as $v) {
        $k = (string)mh_drift_norm_scalar($v);
        if ($k !== '') $out[$k] = true;
    }
    ksort($out);
    return $out;
}

function mh_drift_mount_key(array $m): string {
    $mp = (string)mh_drift_norm_scalar($m['mountpoint'] ?? '');
    $fs = (string)mh_drift_norm_scalar($m['fstype'] ?? '');
    $dev = (string)mh_drift_norm_scalar($m['device'] ?? '');
    return $mp . '|' . $fs . '|' . $dev;
}

function mh_drift_ports_set(array $ports): array {
    $set = [];
    foreach ($ports as $p) {
        if (!is_array($p)) continue;
        $proto = (string)mh_drift_norm_scalar($p['proto'] ?? '');
        $local = (string)mh_drift_norm_scalar($p['local'] ?? '');
        if ($proto === '' || $local === '') continue;
        $set[$proto . '|' . $local] = true;
    }
    ksort($set);
    return $set;
}

function mh_drift_diff_sets(array $old, array $new): array {
    $added = [];
    $removed = [];
    foreach ($new as $k => $_) if (!isset($old[$k])) $added[] = $k;
    foreach ($old as $k => $_) if (!isset($new[$k])) $removed[] = $k;
    sort($added);
    sort($removed);
    return ['added' => $added, 'removed' => $removed];
}

function mh_drift_diff_host(array $oldSnap, array $newSnap): array {
    $changes = [];
    $fields = [
        'os.pretty' => ['os', 'pretty'],
        'os.kernel' => ['os', 'kernel'],
        'cpu.vendor' => ['cpu', 'vendor'],
        'cpu.model' => ['cpu', 'model'],
        'cpu.sockets' => ['cpu', 'sockets'],
        'cpu.cores_per_socket' => ['cpu', 'cores_per_socket'],
        'cpu.threads_total' => ['cpu', 'threads_total'],
        'ram_gb' => ['ram_gb'],
        'gpu.vendor' => ['gpu', 'vendor'],
        'gpu.model' => ['gpu', 'model'],
        'gpu.count' => ['gpu', 'count'],
        'gpu.vram_gb_per_gpu' => ['gpu', 'vram_gb_per_gpu'],
    ];
    foreach ($fields as $name => $p) {
        $old = mh_drift_norm_scalar(mh_drift_pick($oldSnap, $p, null));
        $new = mh_drift_norm_scalar(mh_drift_pick($newSnap, $p, null));
        if ($old !== $new) $changes[$name] = ['from' => $old, 'to' => $new];
    }

    $oldAddrs = [];
    $newAddrs = [];
    foreach ((array)mh_drift_pick($oldSnap, ['network', 'addresses'], []) as $a) {
        if (!is_array($a)) continue;
        $fam = (int)($a['family'] ?? 0);
        $ifn = (string)($a['interface'] ?? '');
        $cidr = (string)($a['cidr'] ?? '');
        if ($fam && $ifn !== '' && $cidr !== '') $oldAddrs[] = $fam . '|' . $ifn . '|' . $cidr;
    }
    foreach ((array)mh_drift_pick($newSnap, ['network', 'addresses'], []) as $a) {
        if (!is_array($a)) continue;
        $fam = (int)($a['family'] ?? 0);
        $ifn = (string)($a['interface'] ?? '');
        $cidr = (string)($a['cidr'] ?? '');
        if ($fam && $ifn !== '' && $cidr !== '') $newAddrs[] = $fam . '|' . $ifn . '|' . $cidr;
    }
    $addrDiff = mh_drift_diff_sets(mh_drift_set_from_list($oldAddrs), mh_drift_set_from_list($newAddrs));

    $oldMounts = [];
    $newMounts = [];
    foreach ((array)mh_drift_pick($oldSnap, ['storage'], []) as $m) {
        if (!is_array($m)) continue;
        $oldMounts[] = mh_drift_mount_key($m);
    }
    foreach ((array)mh_drift_pick($newSnap, ['storage'], []) as $m) {
        if (!is_array($m)) continue;
        $newMounts[] = mh_drift_mount_key($m);
    }
    $mountDiff = mh_drift_diff_sets(mh_drift_set_from_list($oldMounts), mh_drift_set_from_list($newMounts));

    $oldPorts = mh_drift_ports_set((array)mh_drift_pick($oldSnap, ['ports', 'listening'], []));
    $newPorts = mh_drift_ports_set((array)mh_drift_pick($newSnap, ['ports', 'listening'], []));
    $portsDiff = mh_drift_diff_sets($oldPorts, $newPorts);

    return [
        'observed_at' => [
            'from' => (string)mh_drift_pick($oldSnap, ['observed_at'], ''),
            'to' => (string)mh_drift_pick($newSnap, ['observed_at'], ''),
        ],
        'changes' => $changes,
        'diff' => [
            'addresses' => $addrDiff,
            'mounts' => $mountDiff,
            'ports' => $portsDiff,
        ],
        'counts' => [
            'changes' => count($changes),
            'addresses_added' => count($addrDiff['added']),
            'addresses_removed' => count($addrDiff['removed']),
            'mounts_added' => count($mountDiff['added']),
            'mounts_removed' => count($mountDiff['removed']),
            'ports_added' => count($portsDiff['added']),
            'ports_removed' => count($portsDiff['removed']),
        ],
    ];
}

function mh_drift_is_rfc1918(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
    $long = ip2long($ip);
    if (!is_int($long)) return false;
    $ranges = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
    ];
    foreach ($ranges as [$a, $b]) {
        $al = ip2long($a);
        $bl = ip2long($b);
        if (is_int($al) && is_int($bl) && $long >= $al && $long <= $bl) return true;
    }
    return false;
}

function mh_drift_pick_ips(array $snapshot): array {
    $mgmt = null;
    $public = null;
    foreach ((array)mh_drift_pick($snapshot, ['network', 'addresses'], []) as $a) {
        if (!is_array($a) || ($a['family'] ?? null) !== 4) continue;
        $cidr = (string)($a['cidr'] ?? '');
        $ip = trim(explode('/', $cidr)[0] ?? '');
        if ($ip === '' || $ip === '127.0.0.1') continue;
        if ($mgmt === null && mh_drift_is_rfc1918($ip)) $mgmt = $ip;
        if ($public === null && !mh_drift_is_rfc1918($ip)) $public = $ip;
    }
    return ['mgmt' => $mgmt, 'public' => $public];
}

function mh_drift_block_mounts(array $snapshot): string {
    $want = array_fill_keys(['/mysql', '/data', '/vector', '/graph'], true);
    $lines = [];
    foreach ((array)mh_drift_pick($snapshot, ['storage'], []) as $m) {
        if (!is_array($m)) continue;
        $mp = (string)($m['mountpoint'] ?? '');
        if ($mp === '' || !isset($want[$mp])) continue;
        $lines[] = $mp . '|' . (string)($m['fstype'] ?? '') . '|' . (string)($m['device'] ?? '') . '|' . (string)($m['size_gb'] ?? '');
    }
    sort($lines);
    return implode("\n", $lines);
}

function mh_drift_extract_port_number(string $local): ?int {
    $local = trim($local);
    if ($local === '') return null;
    if (preg_match('/:(\d+)$/', $local, $m)) {
        $port = (int)$m[1];
        return ($port > 0 && $port <= 65535) ? $port : null;
    }
    return null;
}

function mh_drift_snapshot_service_set(array $snapshot): array {
    $set = [];
    foreach ((array)mh_drift_pick($snapshot, ['ports', 'listening'], []) as $p) {
        if (!is_array($p)) continue;
        $proto = strtolower(trim((string)($p['proto'] ?? '')));
        if ($proto !== 'tcp' && $proto !== 'udp') continue;
        $port = mh_drift_extract_port_number((string)($p['local'] ?? ''));
        if ($port === null) continue;
        $set[$proto . '|' . $port] = true;
    }
    ksort($set);
    return $set;
}

function mh_drift_netbox_service_set(array $services): array {
    $set = [];
    foreach ($services as $svc) {
        if (!is_array($svc)) continue;
        $proto = strtolower(trim((string)($svc['protocol'] ?? '')));
        $ports = $svc['ports'] ?? [];
        if (!is_array($ports)) $ports = [];
        foreach ($ports as $port) {
            $port = (int)$port;
            if ($proto === '' || $port <= 0 || $port > 65535) continue;
            $set[$proto . '|' . $port] = true;
        }
    }
    ksort($set);
    return $set;
}

function mh_drift_netbox_find_device_by_name(string $baseUrl, string $token, string $name): array {
    $url = rtrim($baseUrl, '/') . '/api/dcim/devices/?name=' . rawurlencode($name);
    $res = mh_nb_http('GET', $url, $token, null);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $res];
    $data = json_decode((string)$res['body'], true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'bad_json'];
    $results = $data['results'] ?? [];
    if (!is_array($results) || empty($results) || !is_array($results[0] ?? null)) return ['ok' => false, 'error' => 'not_found'];
    return ['ok' => true, 'device' => $results[0]];
}

function mh_drift_netbox_services_for_device(string $baseUrl, string $token, int $deviceId): array {
    $paths = [
        '/api/ipam/services/?device_id=' . rawurlencode((string)$deviceId) . '&limit=200',
        '/api/ipam/services/?device=' . rawurlencode((string)$deviceId) . '&limit=200',
    ];
    $last = null;
    foreach ($paths as $path) {
        $res = mh_nb_http('GET', rtrim($baseUrl, '/') . $path, $token, null);
        $last = $res;
        if (!(($res['ok'] ?? false) === true)) continue;
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) return ['ok' => false, 'error' => 'bad_json'];
        $results = $data['results'] ?? [];
        return ['ok' => true, 'services' => is_array($results) ? $results : []];
    }
    return ['ok' => false, 'error' => 'service_lookup_failed', 'detail' => $last];
}

function mh_drift_diff_netbox_sot(array $snapshot, array $device, array $services): array {
    $cf = is_array($device['custom_fields'] ?? null) ? $device['custom_fields'] : [];
    $cpu = is_array($snapshot['cpu'] ?? null) ? $snapshot['cpu'] : [];
    $gpu = is_array($snapshot['gpu'] ?? null) ? $snapshot['gpu'] : [];
    $ips = mh_drift_pick_ips($snapshot);
    $observedFields = [
        'mh_cpu_vendor' => $cpu['vendor'] ?? null,
        'mh_cpu_model' => $cpu['model'] ?? null,
        'mh_cpu_sockets' => isset($cpu['sockets']) ? (int)$cpu['sockets'] : null,
        'mh_cpu_cores_per_socket' => isset($cpu['cores_per_socket']) ? (int)$cpu['cores_per_socket'] : null,
        'mh_cpu_threads_total' => isset($cpu['threads_total']) ? (int)$cpu['threads_total'] : null,
        'mh_ram_gb' => isset($snapshot['ram_gb']) ? (int)$snapshot['ram_gb'] : null,
        'mh_gpu_vendor' => $gpu['vendor'] ?? null,
        'mh_gpu_model' => $gpu['model'] ?? null,
        'mh_gpu_count' => isset($gpu['count']) ? (int)$gpu['count'] : 0,
        'mh_vram_gb_per_gpu' => isset($gpu['vram_gb_per_gpu']) ? (int)$gpu['vram_gb_per_gpu'] : null,
        'mh_primary_mgmt_ip' => $ips['mgmt'],
        'mh_primary_public_ip' => $ips['public'],
        'mh_block_mounts' => mh_drift_block_mounts($snapshot),
    ];

    $fieldDrift = [];
    foreach ($observedFields as $name => $observed) {
        $desired = $cf[$name] ?? null;
        $obsNorm = mh_drift_norm_scalar($observed);
        $desiredNorm = mh_drift_norm_scalar($desired);
        if ($name === 'mh_block_mounts') {
            $obsNorm = trim((string)$obsNorm);
            $desiredNorm = trim((string)$desiredNorm);
        }
        if ($obsNorm !== $desiredNorm) {
            $fieldDrift[$name] = ['observed' => $obsNorm, 'desired' => $desiredNorm];
        }
    }

    $serviceDrift = mh_drift_diff_sets(
        mh_drift_netbox_service_set($services),
        mh_drift_snapshot_service_set($snapshot)
    );

    return [
        'ok' => true,
        'device_id' => (int)($device['id'] ?? 0),
        'field_drift' => $fieldDrift,
        'service_drift' => [
            'observed_missing_in_netbox' => $serviceDrift['added'],
            'declared_missing_on_host' => $serviceDrift['removed'],
        ],
        'counts' => [
            'field_drift' => count($fieldDrift),
            'observed_missing_in_netbox' => count($serviceDrift['added']),
            'declared_missing_on_host' => count($serviceDrift['removed']),
        ],
    ];
}

function mh_drift_to_text(array $report): string {
    $lines = [];
    $lines[] = 'Drift Report';
    $lines[] = 'generated_at=' . ($report['generated_at'] ?? '');
    $lines[] = 'hosts=' . count($report['hosts'] ?? []);
    $lines[] = '';
    foreach (($report['hosts'] ?? []) as $host => $h) {
        if (!is_array($h)) continue;
        $lines[] = "Host: {$host}";
        if (!(($h['ok'] ?? false) === true)) {
            $lines[] = '  status=ERROR';
            $lines[] = '  error=' . (string)($h['error'] ?? '');
            $lines[] = '';
            continue;
        }
        $lines[] = '  from=' . (string)mh_drift_pick($h, ['observed_at', 'from'], '');
        $lines[] = '  to=' . (string)mh_drift_pick($h, ['observed_at', 'to'], '');
        $counts = (array)($h['counts'] ?? []);
        $lines[] = '  changes=' . (string)($counts['changes'] ?? 0) .
            ' ports(+/-)=' . (string)($counts['ports_added'] ?? 0) . '/' . (string)($counts['ports_removed'] ?? 0) .
            ' addrs(+/-)=' . (string)($counts['addresses_added'] ?? 0) . '/' . (string)($counts['addresses_removed'] ?? 0) .
            ' mounts(+/-)=' . (string)($counts['mounts_added'] ?? 0) . '/' . (string)($counts['mounts_removed'] ?? 0);
        foreach ((array)($h['changes'] ?? []) as $k => $chg) {
            if (!is_array($chg)) continue;
            $lines[] = '  field ' . $k . ' ' . json_encode($chg, JSON_UNESCAPED_SLASHES);
        }
        foreach (['ports', 'addresses', 'mounts'] as $cat) {
            $added = (array)mh_drift_pick($h, ['diff', $cat, 'added'], []);
            $removed = (array)mh_drift_pick($h, ['diff', $cat, 'removed'], []);
            if (!empty($added)) {
                $lines[] = '  ' . $cat . ' added:';
                foreach ($added as $v) $lines[] = '    + ' . (string)$v;
            }
            if (!empty($removed)) {
                $lines[] = '  ' . $cat . ' removed:';
                foreach ($removed as $v) $lines[] = '    - ' . (string)$v;
            }
        }
        $sot = $h['sot'] ?? null;
        if (is_array($sot)) {
            if (($sot['ok'] ?? false) === true) {
                $sotCounts = (array)($sot['counts'] ?? []);
                $lines[] = '  sot field_drift=' . (string)($sotCounts['field_drift'] ?? 0) .
                    ' observed_missing_in_netbox=' . (string)($sotCounts['observed_missing_in_netbox'] ?? 0) .
                    ' declared_missing_on_host=' . (string)($sotCounts['declared_missing_on_host'] ?? 0);
            } else {
                $lines[] = '  sot status=ERROR error=' . (string)($sot['error'] ?? '');
            }
        }
        $lines[] = '';
    }
    return implode("\n", $lines) . "\n";
}

$args = $argv;
array_shift($args);

$reportsDir = '';
$outDir = '';
$hostsFilter = [];
$mappingFile = __DIR__ . '/../netbox/mapping.php';
$format = 'json';

foreach ($args as $a) {
    $a = (string)$a;
    if (strpos($a, '--reports=') === 0) {
        $reportsDir = rtrim(trim(substr($a, 10)), '/');
        continue;
    }
    if (strpos($a, '--out=') === 0) {
        $outDir = rtrim(trim(substr($a, 6)), '/');
        continue;
    }
    if (strpos($a, '--hosts=') === 0) {
        $list = trim(substr($a, 8));
        foreach (explode(',', $list) as $h) {
            $h = trim($h);
            if ($h !== '') $hostsFilter[] = $h;
        }
        continue;
    }
    if (strpos($a, '--mapping=') === 0) {
        $mappingFile = trim(substr($a, 10));
        continue;
    }
    if (strpos($a, '--format=') === 0) {
        $format = trim(substr($a, 9));
        continue;
    }
    if ($a === '--help' || $a === '-h') mh_drift_usage();
    mh_drift_usage();
}

if ($reportsDir === '') mh_drift_usage();
$reportsDirReal = realpath($reportsDir) ?: $reportsDir;
if (!is_dir($reportsDirReal)) {
    fwrite(STDERR, "Error: reports dir not found: {$reportsDirReal}\n");
    exit(1);
}

if (!is_file($mappingFile)) {
    fwrite(STDERR, "Error: mapping file not found: {$mappingFile}\n");
    exit(1);
}
require_once $mappingFile;
if (!function_exists('mh_netbox_default_mapping')) {
    fwrite(STDERR, "Error: mapping file must define mh_netbox_default_mapping()\n");
    exit(1);
}
$mapping = mh_netbox_default_mapping();
$map = is_array($mapping['map'] ?? null) ? $mapping['map'] : [];

$hostsFilter = array_values(array_filter(array_unique(array_map('trim', $hostsFilter)), static fn($v) => is_string($v) && $v !== ''));
$hostDirs = mh_drift_list_host_dirs($reportsDirReal);
if (!empty($hostsFilter)) {
    $allowed = array_fill_keys($hostsFilter, true);
    $hostDirs = array_values(array_filter($hostDirs, static fn($h) => isset($allowed[$h])));
}

if ($outDir === '') $outDir = dirname($reportsDirReal) . '/drift-reports';
if (!is_dir($outDir)) @mkdir($outDir, 0755, true);

$report = [
    'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
    'reports_dir' => $reportsDirReal,
    'mapping_file' => $mappingFile,
    'netbox_sot' => ['enabled' => false],
    'hosts' => [],
];

$nbBase = mh_nb_env('NETBOX_URL');
$nbToken = mh_nb_env('NETBOX_TOKEN');
$nbApiBase = '';
if ($nbBase !== '' && $nbToken !== '') {
    $nbApiBase = mh_nb_api_base_url($nbBase);
    $report['netbox_sot'] = ['enabled' => true, 'netbox_url' => $nbApiBase];
}

foreach ($hostDirs as $host) {
    $hostPath = $reportsDirReal . '/' . $host;
    $files = mh_drift_list_json_files($hostPath);
    if (count($files) < 2) {
        $report['hosts'][$host] = ['ok' => false, 'error' => 'need_two_snapshots'];
        continue;
    }
    $oldPath = $files[count($files) - 2];
    $newPath = $files[count($files) - 1];

    $old = mh_drift_read_json($oldPath);
    $new = mh_drift_read_json($newPath);
    if (!(($old['ok'] ?? false) === true)) {
        $report['hosts'][$host] = ['ok' => false, 'error' => 'old_snapshot_read_failed', 'path' => $oldPath];
        continue;
    }
    if (!(($new['ok'] ?? false) === true)) {
        $report['hosts'][$host] = ['ok' => false, 'error' => 'new_snapshot_read_failed', 'path' => $newPath];
        continue;
    }

    $diff = mh_drift_diff_host((array)$old['data'], (array)$new['data']);
    $diff['ok'] = true;
    if ($nbApiBase !== '' && $nbToken !== '') {
        $nbName = isset($map[$host]) ? (string)$map[$host] : '';
        if ($nbName === '') {
            $diff['sot'] = ['ok' => false, 'error' => 'no_mapping'];
        } else {
            $deviceRes = mh_drift_netbox_find_device_by_name($nbApiBase, $nbToken, $nbName);
            if (!(($deviceRes['ok'] ?? false) === true)) {
                $diff['sot'] = ['ok' => false, 'error' => 'device_lookup_failed', 'detail' => $deviceRes];
            } else {
                $device = (array)$deviceRes['device'];
                $servicesRes = mh_drift_netbox_services_for_device($nbApiBase, $nbToken, (int)($device['id'] ?? 0));
                if (!(($servicesRes['ok'] ?? false) === true)) {
                    $diff['sot'] = ['ok' => false, 'error' => 'service_lookup_failed', 'detail' => $servicesRes];
                } else {
                    $diff['sot'] = mh_drift_diff_netbox_sot((array)$new['data'], $device, (array)($servicesRes['services'] ?? []));
                    $diff['sot']['netbox_name'] = $nbName;
                }
            }
        }
    }
    $diff['paths'] = ['from' => $oldPath, 'to' => $newPath];
    $report['hosts'][$host] = $diff;
}

$date = gmdate('Ymd-His');
$jsonPath = $outDir . '/drift-' . $date . '.json';
$textPath = $outDir . '/drift-' . $date . '.txt';

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
@file_put_contents($jsonPath, (string)$json, LOCK_EX);

if ($format === 'text') {
    $txt = mh_drift_to_text($report);
    @file_put_contents($textPath, $txt, LOCK_EX);
    fwrite(STDOUT, $txt);
    fwrite(STDOUT, "Wrote: {$textPath}\n");
} else {
    fwrite(STDOUT, (string)$json . "\n");
    fwrite(STDOUT, "Wrote: {$jsonPath}\n");
}

exit(0);
