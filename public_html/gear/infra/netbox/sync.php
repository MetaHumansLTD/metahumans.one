<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/bootstrap.php';

function mh_netbox_usage(): void {
    $self = basename(__FILE__);
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php {$self} --reports=<dir> [--hosts=h1,h2] [--dry-run]\n");
    fwrite(STDERR, "Required env:\n");
    fwrite(STDERR, "  NETBOX_URL   (e.g. https://netbox.internal)\n");
    fwrite(STDERR, "  NETBOX_TOKEN (API token)\n");
    fwrite(STDERR, "Options:\n");
    fwrite(STDERR, "  --dry-run        Do not write to NetBox (default)\n");
    fwrite(STDERR, "  --write          Perform PATCH updates\n");
    fwrite(STDERR, "  --create-missing Create NetBox site/role/type + devices if missing (requires --write)\n");
    fwrite(STDERR, "  --ensure-schema  Ensure the runbook schema exists before syncing (requires --write)\n");
    fwrite(STDERR, "  --mapping=<php>  Mapping file (default: mapping.php)\n");
    exit(2);
}

function mh_netbox_env(string $k): string {
    $v = getenv($k);
    if (!is_string($v)) return '';
    $v = trim($v);
    $v = trim($v, " \t\n\r\0\x0B'\"`´‘’“”");
    $v = str_replace(['`', '´', '‘', '’', '“', '”'], '', $v);
    $v = trim($v);
    if ($k === 'NETBOX_URL') {
        if (preg_match('/https?:\\/\\/[^\\s"\']+/i', $v, $m)) return trim($m[0], " \t\n\r\0\x0B'\"`´‘’“”");
        $v = preg_replace('/\\s+/', '', $v);
    }
    if ($k === 'NETBOX_TOKEN') {
        $v = preg_replace('/\\s+/', '', $v);
    }
    return $v;
}

function mh_netbox_http(string $method, string $url, string $token, ?array $jsonBody = null): array {
    $ch = curl_init();
    if ($ch === false) return ['ok' => false, 'http_code' => null, 'error' => 'curl_init_failed', 'body' => null];
    $authHeader = (strpos($token, 'nb_') === 0) ? ('Authorization: Bearer ' . $token) : ('Authorization: Token ' . $token);
    $headers = [
        'Accept: application/json',
        $authHeader,
    ];
    $body = null;
    if (is_array($jsonBody)) {
        $body = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'http_code' => $http ?: null, 'error' => $err !== '' ? $err : 'curl_exec_failed', 'body' => null];
    return ['ok' => ($http >= 200 && $http < 300), 'http_code' => $http, 'error' => null, 'body' => $resp];
}

function mh_netbox_slugify(string $s, int $maxLen = 80): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    if ($s === '') $s = 'mh';
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return rtrim($s, '-');
}

function mh_netbox_get_one_by_filter(string $baseUrl, string $token, string $path, array $params): array {
    $qs = http_build_query($params);
    $url = rtrim($baseUrl, '/') . $path . ($qs !== '' ? ('?' . $qs) : '');
    $res = mh_netbox_http('GET', $url, $token, null);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'get_failed', 'detail' => $res];
    $data = json_decode((string)$res['body'], true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'bad_json', 'detail' => $res];
    $results = $data['results'] ?? null;
    if (!is_array($results) || empty($results)) return ['ok' => true, 'found' => false, 'item' => null];
    $item = $results[0];
    return ['ok' => true, 'found' => is_array($item), 'item' => is_array($item) ? $item : null];
}

function mh_netbox_post(string $baseUrl, string $token, string $path, array $payload): array {
    $url = rtrim($baseUrl, '/') . $path;
    return mh_netbox_http('POST', $url, $token, $payload);
}

function mh_netbox_ensure_site(string $baseUrl, string $token, bool $write, string $name): array {
    $slug = mh_netbox_slugify($name);
    $existing = mh_netbox_get_one_by_filter($baseUrl, $token, '/api/dcim/sites/', ['slug' => $slug]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'id' => (int)($existing['item']['id'] ?? 0), 'slug' => $slug];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'slug' => $slug, 'payload' => ['name' => $name, 'slug' => $slug]];
    $create = mh_netbox_post($baseUrl, $token, '/api/dcim/sites/', ['name' => $name, 'slug' => $slug]);
    if (!(($create['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
    $created = json_decode((string)$create['body'], true);
    return ['ok' => true, 'action' => 'created', 'id' => (int)($created['id'] ?? 0), 'slug' => $slug];
}

function mh_netbox_ensure_manufacturer(string $baseUrl, string $token, bool $write, string $name): array {
    $slug = mh_netbox_slugify($name);
    $existing = mh_netbox_get_one_by_filter($baseUrl, $token, '/api/dcim/manufacturers/', ['slug' => $slug]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'id' => (int)($existing['item']['id'] ?? 0), 'slug' => $slug];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'slug' => $slug, 'payload' => ['name' => $name, 'slug' => $slug]];
    $create = mh_netbox_post($baseUrl, $token, '/api/dcim/manufacturers/', ['name' => $name, 'slug' => $slug]);
    if (!(($create['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
    $created = json_decode((string)$create['body'], true);
    return ['ok' => true, 'action' => 'created', 'id' => (int)($created['id'] ?? 0), 'slug' => $slug];
}

function mh_netbox_ensure_device_role(string $baseUrl, string $token, bool $write, string $name, string $slug): array {
    $existing = mh_netbox_get_one_by_filter($baseUrl, $token, '/api/dcim/device-roles/', ['slug' => $slug]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'id' => (int)($existing['item']['id'] ?? 0), 'slug' => $slug];
    $payload = ['name' => $name, 'slug' => $slug, 'color' => '9e9e9e'];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'slug' => $slug, 'payload' => $payload];
    $create = mh_netbox_post($baseUrl, $token, '/api/dcim/device-roles/', $payload);
    if (!(($create['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
    $created = json_decode((string)$create['body'], true);
    return ['ok' => true, 'action' => 'created', 'id' => (int)($created['id'] ?? 0), 'slug' => $slug];
}

function mh_netbox_ensure_device_type(string $baseUrl, string $token, bool $write, int $manufacturerId, string $model, string $slug): array {
    $existing = mh_netbox_get_one_by_filter($baseUrl, $token, '/api/dcim/device-types/', ['slug' => $slug]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'id' => (int)($existing['item']['id'] ?? 0), 'slug' => $slug];
    $payload = [
        'manufacturer' => $manufacturerId,
        'model' => $model,
        'slug' => $slug,
        'u_height' => 1,
        'is_full_depth' => true,
    ];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'slug' => $slug, 'payload' => $payload];
    $create = mh_netbox_post($baseUrl, $token, '/api/dcim/device-types/', $payload);
    if (!(($create['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
    $created = json_decode((string)$create['body'], true);
    return ['ok' => true, 'action' => 'created', 'id' => (int)($created['id'] ?? 0), 'slug' => $slug];
}

function mh_netbox_read_json(string $path): array {
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return ['ok' => false, 'error' => 'read_failed'];
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'json_decode_failed'];
    return ['ok' => true, 'data' => $data];
}

function mh_netbox_latest_snapshot_path(string $reportsDir, string $host): ?string {
    $dir = rtrim($reportsDir, '/') . '/' . $host;
    if (!is_dir($dir)) return null;
    $items = @scandir($dir);
    if (!is_array($items)) return null;
    $files = [];
    foreach ($items as $n) {
        if ($n === '.' || $n === '..') continue;
        if (substr($n, -5) !== '.json') continue;
        $p = $dir . '/' . $n;
        if (is_file($p)) $files[] = $p;
    }
    sort($files);
    if (empty($files)) return null;
    return $files[count($files) - 1];
}

function mh_netbox_is_rfc1918(string $ip): bool {
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

function mh_netbox_pick_ips(array $snapshot): array {
    $mgmt = null;
    $public = null;
    $addrs = $snapshot['network']['addresses'] ?? [];
    if (!is_array($addrs)) $addrs = [];
    foreach ($addrs as $a) {
        if (!is_array($a)) continue;
        if (($a['family'] ?? null) !== 4) continue;
        $cidr = (string)($a['cidr'] ?? '');
        $ip = trim(explode('/', $cidr)[0] ?? '');
        if ($ip === '' || $ip === '127.0.0.1') continue;
        if ($mgmt === null && mh_netbox_is_rfc1918($ip)) $mgmt = $ip;
        if ($public === null && !mh_netbox_is_rfc1918($ip)) $public = $ip;
    }
    return ['mgmt' => $mgmt, 'public' => $public];
}

function mh_netbox_block_mounts(array $snapshot): string {
    $want = array_fill_keys(['/mysql', '/data', '/vector', '/graph'], true);
    $lines = [];
    $storage = $snapshot['storage'] ?? [];
    if (!is_array($storage)) return '';
    foreach ($storage as $m) {
        if (!is_array($m)) continue;
        $mp = (string)($m['mountpoint'] ?? '');
        if ($mp === '' || !isset($want[$mp])) continue;
        $fstype = (string)($m['fstype'] ?? '');
        $dev = (string)($m['device'] ?? '');
        $sizeGb = $m['size_gb'] ?? null;
        $sizeGb = is_int($sizeGb) ? $sizeGb : null;
        $lines[] = $mp . '|' . $fstype . '|' . $dev . '|' . ($sizeGb !== null ? (string)$sizeGb : '');
    }
    return implode("\n", $lines);
}

function mh_netbox_detect_storage_class(array $snapshot): ?string {
    $storage = $snapshot['storage'] ?? [];
    if (!is_array($storage) || empty($storage)) return null;
    $seen = [];
    foreach ($storage as $m) {
        if (!is_array($m)) continue;
        $device = strtolower((string)($m['device'] ?? ''));
        if ($device === '') continue;
        if (strpos($device, 'nvme') !== false) {
            $seen['nvme'] = true;
            continue;
        }
        if (strpos($device, 'sd') !== false || strpos($device, 'vd') !== false || strpos($device, 'xvd') !== false) {
            $seen['ssd'] = true;
        }
    }
    if (count($seen) > 1) return 'mixed';
    if (isset($seen['nvme'])) return 'nvme';
    if (isset($seen['ssd'])) return 'ssd';
    return null;
}

function mh_netbox_mount_size(array $snapshot, string $mountpoint): ?int {
    $storage = $snapshot['storage'] ?? [];
    if (!is_array($storage)) return null;
    foreach ($storage as $m) {
        if (!is_array($m)) continue;
        if ((string)($m['mountpoint'] ?? '') !== $mountpoint) continue;
        $size = $m['size_gb'] ?? null;
        return is_int($size) ? $size : null;
    }
    return null;
}

function mh_netbox_payload_from_snapshot(array $snapshot): array {
    $cpu = is_array($snapshot['cpu'] ?? null) ? $snapshot['cpu'] : [];
    $gpu = is_array($snapshot['gpu'] ?? null) ? $snapshot['gpu'] : [];
    $ips = mh_netbox_pick_ips($snapshot);
    $blockMounts = mh_netbox_block_mounts($snapshot);
    $storageClass = mh_netbox_detect_storage_class($snapshot);
    $rootFsGb = mh_netbox_mount_size($snapshot, '/');
    $dataFsGb = mh_netbox_mount_size($snapshot, '/data');

    $cf = [
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
        'mh_storage_class' => $storageClass,
        'mh_root_fs_gb' => $rootFsGb,
        'mh_data_fs_gb' => $dataFsGb,
        'mh_primary_mgmt_ip' => $ips['mgmt'],
        'mh_primary_public_ip' => $ips['public'],
        'mh_block_mounts' => $blockMounts !== '' ? $blockMounts : null,
    ];

    foreach ($cf as $k => $v) {
        if ($v === null || $v === '') unset($cf[$k]);
    }

    return ['custom_fields' => $cf];
}

function mh_netbox_find_device_by_name(string $baseUrl, string $token, string $name): array {
    $url = rtrim($baseUrl, '/') . '/api/dcim/devices/?name=' . rawurlencode($name);
    $res = mh_netbox_http('GET', $url, $token, null);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'netbox_lookup_failed', 'detail' => $res];
    $data = json_decode((string)$res['body'], true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'netbox_bad_json'];
    $results = $data['results'] ?? null;
    if (!is_array($results) || empty($results)) return ['ok' => false, 'error' => 'not_found'];
    $dev = $results[0];
    if (!is_array($dev) || !isset($dev['id'])) return ['ok' => false, 'error' => 'bad_result'];
    return ['ok' => true, 'device' => $dev];
}

function mh_netbox_create_device(string $baseUrl, string $token, array $payload): array {
    $url = rtrim($baseUrl, '/') . '/api/dcim/devices/';
    $res = mh_netbox_http('POST', $url, $token, $payload);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $res];
    $created = json_decode((string)$res['body'], true);
    if (!is_array($created) || !isset($created['id'])) return ['ok' => false, 'error' => 'bad_create_response', 'detail' => $res];
    return ['ok' => true, 'device' => $created];
}

function mh_netbox_patch_device(int $id, string $baseUrl, string $token, array $payload): array {
    $url = rtrim($baseUrl, '/') . '/api/dcim/devices/' . $id . '/';
    $res = mh_netbox_http('PATCH', $url, $token, $payload);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'patch_failed', 'detail' => $res];
    return ['ok' => true, 'detail' => $res];
}

function mh_netbox_extract_port_number(string $local): ?int {
    $local = trim($local);
    if ($local === '') return null;
    if (preg_match('/:(\d+)$/', $local, $m)) {
        $port = (int)$m[1];
        return ($port > 0 && $port <= 65535) ? $port : null;
    }
    return null;
}

function mh_netbox_service_name(string $proto, int $port): string {
    $known = [
        'tcp:22' => 'ssh',
        'tcp:80' => 'http',
        'tcp:443' => 'https',
        'tcp:6443' => 'k8s-api',
        'tcp:19999' => 'netdata',
    ];
    $key = strtolower($proto) . ':' . $port;
    return $known[$key] ?? (strtolower($proto) . '-' . $port);
}

function mh_netbox_service_payloads_from_snapshot(array $snapshot, int $deviceId): array {
    $ports = $snapshot['ports']['listening'] ?? [];
    if (!is_array($ports)) $ports = [];
    $set = [];
    foreach ($ports as $p) {
        if (!is_array($p)) continue;
        $proto = strtolower(trim((string)($p['proto'] ?? '')));
        if ($proto !== 'tcp' && $proto !== 'udp') continue;
        $port = mh_netbox_extract_port_number((string)($p['local'] ?? ''));
        if ($port === null) continue;
        $set[$proto . '|' . $port] = ['protocol' => $proto, 'port' => $port];
    }
    ksort($set);

    $payloads = [];
    foreach ($set as $item) {
        $payloads[] = [
            'device' => $deviceId,
            'name' => mh_netbox_service_name((string)$item['protocol'], (int)$item['port']),
            'protocol' => (string)$item['protocol'],
            'ports' => [(int)$item['port']],
            'description' => 'Managed by Meta Humans infra sync (observed SSH snapshot)',
        ];
    }
    return $payloads;
}

function mh_netbox_service_key(string $proto, array $ports): string {
    $cleanPorts = [];
    foreach ($ports as $port) {
        $port = (int)$port;
        if ($port > 0 && $port <= 65535) {
            $cleanPorts[] = $port;
        }
    }
    sort($cleanPorts);
    return strtolower(trim($proto)) . '|' . implode(',', $cleanPorts);
}

function mh_netbox_list_services_for_device(string $baseUrl, string $token, int $deviceId): array {
    $paths = [
        '/api/ipam/services/?device_id=' . rawurlencode((string)$deviceId) . '&limit=200',
        '/api/ipam/services/?device=' . rawurlencode((string)$deviceId) . '&limit=200',
    ];
    $last = null;
    foreach ($paths as $path) {
        $url = rtrim($baseUrl, '/') . $path;
        $res = mh_netbox_http('GET', $url, $token, null);
        $last = $res;
        if (!(($res['ok'] ?? false) === true)) {
            continue;
        }
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'bad_json', 'detail' => $res];
        }
        $results = $data['results'] ?? [];
        if (!is_array($results)) {
            $results = [];
        }
        return ['ok' => true, 'services' => $results];
    }
    return ['ok' => false, 'error' => 'service_lookup_failed', 'detail' => $last];
}

function mh_netbox_create_service(string $baseUrl, string $token, array $payload): array {
    $url = rtrim($baseUrl, '/') . '/api/ipam/services/';
    $res = mh_netbox_http('POST', $url, $token, $payload);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $res];
    $created = json_decode((string)$res['body'], true);
    return ['ok' => true, 'service' => is_array($created) ? $created : null];
}

function mh_netbox_sync_device_services(string $baseUrl, string $token, int $deviceId, array $desiredPayloads, bool $write): array {
    $currentRes = mh_netbox_list_services_for_device($baseUrl, $token, $deviceId);
    if (!(($currentRes['ok'] ?? false) === true)) {
        return ['ok' => false, 'error' => 'service_lookup_failed', 'detail' => $currentRes];
    }

    $currentMap = [];
    foreach (($currentRes['services'] ?? []) as $svc) {
        if (!is_array($svc)) continue;
        $key = mh_netbox_service_key((string)($svc['protocol'] ?? ''), (array)($svc['ports'] ?? []));
        if ($key === '|') continue;
        $currentMap[$key] = $svc;
    }

    $desiredMap = [];
    foreach ($desiredPayloads as $payload) {
        if (!is_array($payload)) continue;
        $key = mh_netbox_service_key((string)($payload['protocol'] ?? ''), (array)($payload['ports'] ?? []));
        if ($key === '|') continue;
        $desiredMap[$key] = $payload;
    }

    $toCreate = [];
    foreach ($desiredMap as $key => $payload) {
        if (!isset($currentMap[$key])) {
            $toCreate[$key] = $payload;
        }
    }

    $stale = [];
    foreach ($currentMap as $key => $svc) {
        $desc = strtolower(trim((string)($svc['description'] ?? '')));
        if (!isset($desiredMap[$key]) && strpos($desc, 'managed by meta humans infra sync') !== false) {
            $stale[] = $key;
        }
    }
    sort($stale);

    $result = [
        'ok' => true,
        'current_count' => count($currentMap),
        'desired_count' => count($desiredMap),
        'to_create' => array_values($toCreate),
        'stale_managed' => $stale,
        'created' => [],
    ];

    if ($write) {
        foreach ($toCreate as $payload) {
            $create = mh_netbox_create_service($baseUrl, $token, $payload);
            if (!(($create['ok'] ?? false) === true)) {
                $result['ok'] = false;
                $result['error'] = 'service_create_failed';
                $result['detail'] = $create;
                return $result;
            }
            $result['created'][] = $create['service'];
        }
    }

    return $result;
}

$args = $argv;
array_shift($args);

$reportsDir = '';
$hostsFilter = [];
$mappingFile = __DIR__ . '/mapping.php';
$write = false;
$createMissing = false;
$ensureSchema = false;

foreach ($args as $a) {
    $a = (string)$a;
    if (strpos($a, '--reports=') === 0) {
        $reportsDir = rtrim(trim(substr($a, 10)), '/');
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
    if ($a === '--write') {
        $write = true;
        continue;
    }
    if ($a === '--create-missing') {
        $createMissing = true;
        continue;
    }
    if ($a === '--ensure-schema') {
        $ensureSchema = true;
        continue;
    }
    if ($a === '--dry-run') {
        $write = false;
        continue;
    }
    if ($a === '--help' || $a === '-h') mh_netbox_usage();
    mh_netbox_usage();
}

if ($reportsDir === '') mh_netbox_usage();
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
if (empty($hostsFilter)) $hostsFilter = array_keys($map);

$baseUrl = mh_netbox_env('NETBOX_URL');
$token = mh_netbox_env('NETBOX_TOKEN');

if ($write) {
    if ($baseUrl === '' || $token === '') {
        fwrite(STDERR, "Error: NETBOX_URL and NETBOX_TOKEN must be set for --write\n");
        exit(1);
    }
}

$apiBaseUrl = $baseUrl;
if ($write && $apiBaseUrl !== '') {
    $apiBaseUrl = rtrim($apiBaseUrl, '/');
    $p = parse_url($apiBaseUrl, PHP_URL_PATH);
    if ($p === null || $p === '' || $p === '/') $apiBaseUrl .= '/netbox';
}

if ($createMissing) {
    $ensureSchema = true;
}

$summary = [
    'mode' => $write ? 'write' : 'dry-run',
    'reports_dir' => $reportsDirReal,
    'netbox_url' => $apiBaseUrl !== '' ? $apiBaseUrl : null,
    'create_missing' => $createMissing,
    'ensure_schema' => $ensureSchema,
    'schema' => null,
    'ensured' => null,
    'hosts' => [],
];

if ($write && $ensureSchema) {
    $summary['schema'] = mh_nb_ensure_runbook_schema($apiBaseUrl, $token, true);
}

$ensure = null;
if ($write && $createMissing) {
    $site = mh_netbox_ensure_site($apiBaseUrl, $token, true, 'MetaHumans');
    if (!(($site['ok'] ?? false) === true) || (int)($site['id'] ?? 0) <= 0) {
        $summary['ensured'] = ['ok' => false, 'error' => 'ensure_site_failed', 'detail' => $site];
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    $mfg = mh_netbox_ensure_manufacturer($apiBaseUrl, $token, true, 'MetaHumans');
    if (!(($mfg['ok'] ?? false) === true) || (int)($mfg['id'] ?? 0) <= 0) {
        $summary['ensured'] = ['ok' => false, 'error' => 'ensure_manufacturer_failed', 'detail' => $mfg];
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    $role = mh_netbox_ensure_device_role($apiBaseUrl, $token, true, 'MH Server', 'mh-server');
    if (!(($role['ok'] ?? false) === true) || (int)($role['id'] ?? 0) <= 0) {
        $summary['ensured'] = ['ok' => false, 'error' => 'ensure_role_failed', 'detail' => $role];
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    $dtype = mh_netbox_ensure_device_type($apiBaseUrl, $token, true, (int)$mfg['id'], 'MH Linux Server', 'mh-linux-server');
    if (!(($dtype['ok'] ?? false) === true) || (int)($dtype['id'] ?? 0) <= 0) {
        $summary['ensured'] = ['ok' => false, 'error' => 'ensure_device_type_failed', 'detail' => $dtype];
        fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        exit(1);
    }
    $ensure = [
        'ok' => true,
        'site' => $site,
        'manufacturer' => $mfg,
        'device_role' => $role,
        'device_type' => $dtype,
        'ids' => [
            'site_id' => (int)$site['id'],
            'role_id' => (int)$role['id'],
            'device_type_id' => (int)$dtype['id'],
        ],
    ];
    $summary['ensured'] = $ensure;
}

foreach ($hostsFilter as $host) {
    $nbName = isset($map[$host]) ? (string)$map[$host] : '';
    if ($nbName === '') {
        $summary['hosts'][$host] = ['ok' => false, 'error' => 'no_mapping'];
        continue;
    }
    $snapPath = mh_netbox_latest_snapshot_path($reportsDirReal, $host);
    if ($snapPath === null) {
        $summary['hosts'][$host] = ['ok' => false, 'error' => 'no_snapshot'];
        continue;
    }
    $snapRes = mh_netbox_read_json($snapPath);
    if (!(($snapRes['ok'] ?? false) === true)) {
        $summary['hosts'][$host] = ['ok' => false, 'error' => 'snapshot_read_failed', 'path' => $snapPath];
        continue;
    }
    $snapshot = (array)$snapRes['data'];
    $payload = mh_netbox_payload_from_snapshot($snapshot);

    $hostOut = [
        'ok' => true,
        'snapshot' => $snapPath,
        'netbox_name' => $nbName,
        'payload' => $payload,
    ];

    if ($write) {
        $found = mh_netbox_find_device_by_name($apiBaseUrl, $token, $nbName);
        if (!(($found['ok'] ?? false) === true)) {
            if ($createMissing && is_array($ensure) && ($found['error'] ?? '') === 'not_found') {
                $createPayload = [
                    'name' => $nbName,
                    'site' => (int)$ensure['ids']['site_id'],
                    'role' => (int)$ensure['ids']['role_id'],
                    'device_type' => (int)$ensure['ids']['device_type_id'],
                    'status' => 'active',
                ];
                $created = mh_netbox_create_device($apiBaseUrl, $token, $createPayload);
                if (!(($created['ok'] ?? false) === true)) {
                    $hostOut['ok'] = false;
                    $hostOut['error'] = 'netbox_device_create_failed';
                    $hostOut['detail'] = $created;
                    $summary['hosts'][$host] = $hostOut;
                    continue;
                }
                $hostOut['created'] = true;
                $found = ['ok' => true, 'device' => $created['device']];
            } else {
                $hostOut['ok'] = false;
                $hostOut['error'] = 'netbox_device_not_found';
                $hostOut['detail'] = $found;
                $summary['hosts'][$host] = $hostOut;
                continue;
            }
        }
        $dev = (array)$found['device'];
        $id = (int)($dev['id'] ?? 0);
        if ($id <= 0) {
            $hostOut['ok'] = false;
            $hostOut['error'] = 'netbox_bad_device_id';
            $summary['hosts'][$host] = $hostOut;
            continue;
        }
        $patch = mh_netbox_patch_device($id, $apiBaseUrl, $token, $payload);
        if (!(($patch['ok'] ?? false) === true)) {
            $hostOut['ok'] = false;
            $hostOut['error'] = 'netbox_patch_failed';
            $hostOut['detail'] = $patch;
        } else {
            $hostOut['patched'] = true;
            $services = mh_netbox_service_payloads_from_snapshot($snapshot, $id);
            $hostOut['services'] = mh_netbox_sync_device_services($apiBaseUrl, $token, $id, $services, true);
            if (!(($hostOut['services']['ok'] ?? false) === true)) {
                $hostOut['ok'] = false;
                $hostOut['error'] = 'netbox_service_sync_failed';
            }
        }
    } else {
        $hostOut['services'] = [
            'ok' => true,
            'desired' => mh_netbox_service_payloads_from_snapshot($snapshot, 0),
        ];
    }

    $summary['hosts'][$host] = $hostOut;
}

fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
exit(0);
