<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

function mh_nb_bootstrap_usage(): void {
    $self = basename(__FILE__);
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php {$self} --dry-run\n");
    fwrite(STDERR, "  php {$self} --write\n");
    fwrite(STDERR, "Required env:\n");
    fwrite(STDERR, "  NETBOX_URL\n");
    fwrite(STDERR, "  NETBOX_TOKEN\n");
    exit(2);
}

function mh_nb_env(string $k): string {
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

function mh_nb_api_base_url(string $baseUrl): string {
    $apiBaseUrl = rtrim(trim($baseUrl), '/');
    $p = parse_url($apiBaseUrl, PHP_URL_PATH);
    if ($p === null || $p === '' || $p === '/') {
        $apiBaseUrl .= '/netbox';
    }
    return $apiBaseUrl;
}

function mh_nb_slugify(string $s, int $maxLen = 80): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    if ($s === '') {
        $s = 'mh';
    }
    if (strlen($s) > $maxLen) {
        $s = substr($s, 0, $maxLen);
    }
    return rtrim($s, '-');
}

function mh_nb_http(string $method, string $url, string $token, ?array $jsonBody = null): array {
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
        CURLOPT_TIMEOUT => 25,
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

function mh_nb_get_one_by_filter(string $baseUrl, string $token, string $path, array $params): array {
    $qs = http_build_query($params);
    $url = rtrim($baseUrl, '/') . $path . ($qs !== '' ? ('?' . $qs) : '');
    $res = mh_nb_http('GET', $url, $token, null);
    if (!(($res['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'get_failed', 'detail' => $res];
    $data = json_decode((string)$res['body'], true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'bad_json', 'detail' => $res];
    $results = $data['results'] ?? null;
    if (!is_array($results) || empty($results)) return ['ok' => true, 'found' => false, 'item' => null];
    $item = $results[0];
    return ['ok' => true, 'found' => is_array($item), 'item' => is_array($item) ? $item : null];
}

function mh_nb_post(string $baseUrl, string $token, string $path, array $payload): array {
    $url = rtrim($baseUrl, '/') . $path;
    return mh_nb_http('POST', $url, $token, $payload);
}

function mh_nb_runbook_custom_fields(): array {
    $objectTypes = ['dcim.device', 'virtualization.virtualmachine'];
    return [
        ['name' => 'mh_cpu_vendor', 'label' => 'MH CPU Vendor', 'type' => 'text', 'required' => false, 'description' => 'Runbook: CPU vendor.', 'object_types' => $objectTypes],
        ['name' => 'mh_cpu_model', 'label' => 'MH CPU Model', 'type' => 'text', 'required' => false, 'description' => 'Runbook: CPU model.', 'object_types' => $objectTypes],
        ['name' => 'mh_cpu_sockets', 'label' => 'MH CPU Sockets', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: socket count.', 'object_types' => $objectTypes],
        ['name' => 'mh_cpu_cores_per_socket', 'label' => 'MH CPU Cores Per Socket', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: cores per socket.', 'object_types' => $objectTypes],
        ['name' => 'mh_cpu_threads_total', 'label' => 'MH CPU Threads Total', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: total CPU threads.', 'object_types' => $objectTypes],
        ['name' => 'mh_ram_gb', 'label' => 'MH RAM (GB)', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: RAM in GB.', 'object_types' => $objectTypes],
        ['name' => 'mh_gpu_vendor', 'label' => 'MH GPU Vendor', 'type' => 'text', 'required' => false, 'description' => 'Runbook: GPU vendor.', 'object_types' => $objectTypes],
        ['name' => 'mh_gpu_model', 'label' => 'MH GPU Model', 'type' => 'text', 'required' => false, 'description' => 'Runbook: GPU model.', 'object_types' => $objectTypes],
        ['name' => 'mh_gpu_count', 'label' => 'MH GPU Count', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: GPU count.', 'object_types' => $objectTypes],
        ['name' => 'mh_vram_gb_per_gpu', 'label' => 'MH VRAM (GB) Per GPU', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: VRAM per GPU.', 'object_types' => $objectTypes],
        ['name' => 'mh_cuda_driver_version', 'label' => 'MH CUDA Driver Version', 'type' => 'text', 'required' => false, 'description' => 'Runbook: CUDA/NVIDIA driver version.', 'object_types' => $objectTypes],
        ['name' => 'mh_storage_class', 'label' => 'MH Storage Class', 'type' => 'text', 'required' => false, 'description' => 'Runbook choice: nvme, ssd, hdd, mixed.', 'object_types' => $objectTypes],
        ['name' => 'mh_root_fs_gb', 'label' => 'MH Root FS (GB)', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: root filesystem size.', 'object_types' => $objectTypes],
        ['name' => 'mh_data_fs_gb', 'label' => 'MH Data FS (GB)', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: data filesystem size.', 'object_types' => $objectTypes],
        ['name' => 'mh_block_mounts', 'label' => 'MH Block Mounts', 'type' => 'longtext', 'required' => false, 'description' => 'Runbook multiline format: /mount|fstype|device|size_gb', 'object_types' => $objectTypes],
        ['name' => 'mh_object_storage_endpoints', 'label' => 'MH Object Storage Endpoints', 'type' => 'longtext', 'required' => false, 'description' => 'Runbook multiline format: name|type|endpoint', 'object_types' => $objectTypes],
        ['name' => 'mh_fabric_name', 'label' => 'MH Fabric Name', 'type' => 'text', 'required' => false, 'description' => 'Runbook: fabric name.', 'object_types' => $objectTypes],
        ['name' => 'mh_fabric_vlan_id', 'label' => 'MH Fabric VLAN ID', 'type' => 'integer', 'required' => false, 'description' => 'Runbook: fabric VLAN.', 'object_types' => $objectTypes],
        ['name' => 'mh_primary_mgmt_ip', 'label' => 'MH Primary Mgmt IP', 'type' => 'text', 'required' => false, 'description' => 'Runbook quick-reference management IP.', 'object_types' => $objectTypes],
        ['name' => 'mh_primary_public_ip', 'label' => 'MH Primary Public IP', 'type' => 'text', 'required' => false, 'description' => 'Runbook quick-reference public IP.', 'object_types' => $objectTypes],
        ['name' => 'mh_public_fqdn', 'label' => 'MH Public FQDN', 'type' => 'text', 'required' => false, 'description' => 'Runbook public FQDN.', 'object_types' => $objectTypes],
        ['name' => 'mh_private_fqdn', 'label' => 'MH Private FQDN', 'type' => 'text', 'required' => false, 'description' => 'Runbook private FQDN.', 'object_types' => $objectTypes],
        ['name' => 'mh_environment', 'label' => 'MH Environment', 'type' => 'text', 'required' => false, 'description' => 'Runbook choice: prod, stage, dev.', 'object_types' => $objectTypes],
        ['name' => 'mh_owner_team', 'label' => 'MH Owner Team', 'type' => 'text', 'required' => false, 'description' => 'Runbook owner team.', 'object_types' => $objectTypes],
        ['name' => 'mh_lifecycle_state', 'label' => 'MH Lifecycle State', 'type' => 'text', 'required' => false, 'description' => 'Runbook choice: active, spare, retiring, decommissioned.', 'object_types' => $objectTypes],
        ['name' => 'mh_ha_group', 'label' => 'MH HA Group', 'type' => 'text', 'required' => false, 'description' => 'Runbook HA group.', 'object_types' => $objectTypes],
        ['name' => 'mh_ha_role', 'label' => 'MH HA Role', 'type' => 'text', 'required' => false, 'description' => 'Runbook choice: active, passive, active-active.', 'object_types' => $objectTypes],
        ['name' => 'mh_ha_peer', 'label' => 'MH HA Peer', 'type' => 'text', 'required' => false, 'description' => 'Runbook peer device/VM name.', 'object_types' => $objectTypes],
        ['name' => 'mh_ha_vips', 'label' => 'MH HA VIPs', 'type' => 'longtext', 'required' => false, 'description' => 'Runbook multiline format: vip|vrf|purpose', 'object_types' => $objectTypes],
        ['name' => 'mh_ha_method', 'label' => 'MH HA Method', 'type' => 'text', 'required' => false, 'description' => 'Runbook choice: keepalived, cloud-lb, bgp-anycast, k8s-service, manual.', 'object_types' => $objectTypes],
        ['name' => 'mh_ha_failover_test', 'label' => 'MH HA Failover Test', 'type' => 'longtext', 'required' => false, 'description' => 'Runbook: one verification command per line.', 'object_types' => $objectTypes],
    ];
}

function mh_nb_runbook_tags(): array {
    return [
        ['name' => 'mh:gpu', 'slug' => 'mh-gpu'],
        ['name' => 'mh:rke2', 'slug' => 'mh-rke2'],
        ['name' => 'mh:control-plane', 'slug' => 'mh-control-plane'],
        ['name' => 'mh:ingress', 'slug' => 'mh-ingress'],
        ['name' => 'mh:api', 'slug' => 'mh-api'],
        ['name' => 'mh:storage', 'slug' => 'mh-storage'],
        ['name' => 'mh:public-exposed', 'slug' => 'mh-public-exposed'],
        ['name' => 'mh:fabric-only', 'slug' => 'mh-fabric-only'],
    ];
}

function mh_nb_runbook_vrfs(): array {
    return [
        ['name' => 'public', 'enforce_unique' => false, 'description' => 'Meta Humans public/global routing'],
        ['name' => 'mh-fabric', 'enforce_unique' => false, 'description' => 'Meta Humans private fabric routing'],
        ['name' => 'storage', 'enforce_unique' => false, 'description' => 'Meta Humans storage routing'],
    ];
}

function mh_nb_runbook_prefixes(): array {
    return [
        ['prefix' => '10.10.0.0/16', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Management (mh-fabric)'],
        ['prefix' => '10.10.10.0/24', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Control-plane management'],
        ['prefix' => '10.10.20.0/24', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Worker management'],
        ['prefix' => '10.10.30.0/24', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Ingress/API management'],
        ['prefix' => '10.20.0.0/16', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Service-to-service fabric'],
        ['prefix' => '10.20.10.0/24', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Internal VIPs'],
        ['prefix' => '10.20.20.0/24', 'vrf' => 'mh-fabric', 'status' => 'active', 'description' => 'Storage endpoints on fabric'],
        ['prefix' => '10.30.0.0/16', 'vrf' => 'storage', 'status' => 'active', 'description' => 'Storage network'],
        ['prefix' => '10.30.10.0/24', 'vrf' => 'storage', 'status' => 'active', 'description' => 'Block storage fabric'],
    ];
}

function mh_nb_ensure_custom_field(string $baseUrl, string $token, bool $write, array $def): array {
    $name = (string)($def['name'] ?? '');
    if ($name === '') return ['ok' => false, 'error' => 'bad_def'];
    $existing = mh_nb_get_one_by_filter($baseUrl, $token, '/api/extras/custom-fields/', ['name' => $name]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'name' => $name];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'name' => $name, 'payload' => $def];

    $create = mh_nb_post($baseUrl, $token, '/api/extras/custom-fields/', $def);
    if (($create['ok'] ?? false) === true) return ['ok' => true, 'action' => 'created', 'name' => $name];

    if (($def['type'] ?? '') === 'longtext') {
        $fallback = $def;
        $fallback['type'] = 'text';
        $create2 = mh_nb_post($baseUrl, $token, '/api/extras/custom-fields/', $fallback);
        if (($create2['ok'] ?? false) === true) return ['ok' => true, 'action' => 'created_fallback_text', 'name' => $name];
        return ['ok' => false, 'error' => 'create_failed', 'detail' => $create2, 'detail_primary' => $create];
    }

    return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
}

function mh_nb_ensure_tag(string $baseUrl, string $token, bool $write, string $name, string $slug): array {
    $existing = mh_nb_get_one_by_filter($baseUrl, $token, '/api/extras/tags/', ['slug' => $slug]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'slug' => $slug];
    $payload = ['name' => $name, 'slug' => $slug];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'slug' => $slug, 'payload' => $payload];
    $create = mh_nb_post($baseUrl, $token, '/api/extras/tags/', $payload);
    if (($create['ok'] ?? false) === true) return ['ok' => true, 'action' => 'created', 'slug' => $slug];
    return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
}

function mh_nb_ensure_vrf(string $baseUrl, string $token, bool $write, array $def): array {
    $name = (string)($def['name'] ?? '');
    if ($name === '') return ['ok' => false, 'error' => 'bad_def'];
    $existing = mh_nb_get_one_by_filter($baseUrl, $token, '/api/ipam/vrfs/', ['name' => $name]);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) {
        return ['ok' => true, 'action' => 'exists', 'name' => $name, 'id' => (int)($existing['item']['id'] ?? 0)];
    }
    $payload = [
        'name' => $name,
        'rd' => (string)($def['rd'] ?? ('mh:' . mh_nb_slugify($name, 40))),
        'enforce_unique' => (bool)($def['enforce_unique'] ?? false),
        'description' => (string)($def['description'] ?? ''),
    ];
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'name' => $name, 'payload' => $payload];
    $create = mh_nb_post($baseUrl, $token, '/api/ipam/vrfs/', $payload);
    if (!(($create['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
    $created = json_decode((string)$create['body'], true);
    return ['ok' => true, 'action' => 'created', 'name' => $name, 'id' => (int)($created['id'] ?? 0)];
}

function mh_nb_ensure_prefix(string $baseUrl, string $token, bool $write, array $def, array $vrfIdsByName): array {
    $prefix = (string)($def['prefix'] ?? '');
    if ($prefix === '') return ['ok' => false, 'error' => 'bad_def'];
    $params = ['prefix' => $prefix];
    $vrfName = (string)($def['vrf'] ?? '');
    if ($vrfName !== '' && isset($vrfIdsByName[$vrfName]) && (int)$vrfIdsByName[$vrfName] > 0) {
        $params['vrf_id'] = (int)$vrfIdsByName[$vrfName];
    }
    $existing = mh_nb_get_one_by_filter($baseUrl, $token, '/api/ipam/prefixes/', $params);
    if (!(($existing['ok'] ?? false) === true)) return ['ok' => false, 'error' => 'lookup_failed', 'detail' => $existing];
    if (($existing['found'] ?? false) === true) return ['ok' => true, 'action' => 'exists', 'prefix' => $prefix];

    $payload = [
        'prefix' => $prefix,
        'status' => (string)($def['status'] ?? 'active'),
        'description' => (string)($def['description'] ?? ''),
    ];
    if ($vrfName !== '' && isset($vrfIdsByName[$vrfName]) && (int)$vrfIdsByName[$vrfName] > 0) {
        $payload['vrf'] = (int)$vrfIdsByName[$vrfName];
    }
    if (!$write) return ['ok' => true, 'action' => 'would_create', 'prefix' => $prefix, 'payload' => $payload];
    $create = mh_nb_post($baseUrl, $token, '/api/ipam/prefixes/', $payload);
    if (($create['ok'] ?? false) === true) return ['ok' => true, 'action' => 'created', 'prefix' => $prefix];
    return ['ok' => false, 'error' => 'create_failed', 'detail' => $create];
}

function mh_nb_runbook_service_profiles(): array {
    return [
        'notes' => [
            'NetBox Services are per device or VM, not a global taxonomy object.',
            'The sync script now creates or compares device services from observed listening ports.',
            'Use the mapping file to refine role-based intended exposure over time.',
        ],
        'examples' => [
            'ingress' => ['tcp/80', 'tcp/443'],
            'monitoring' => ['tcp/19999'],
            'control-plane' => ['tcp/6443'],
        ],
    ];
}

function mh_nb_ensure_runbook_schema(string $baseUrl, string $token, bool $write): array {
    $out = [
        'mode' => $write ? 'write' : 'dry-run',
        'netbox_url' => $baseUrl,
        'custom_fields' => [],
        'tags' => [],
        'vrfs' => [],
        'prefixes' => [],
        'services' => mh_nb_runbook_service_profiles(),
    ];

    foreach (mh_nb_runbook_custom_fields() as $cf) {
        $out['custom_fields'][] = mh_nb_ensure_custom_field($baseUrl, $token, $write, $cf);
    }
    foreach (mh_nb_runbook_tags() as $tag) {
        $out['tags'][] = mh_nb_ensure_tag($baseUrl, $token, $write, (string)$tag['name'], (string)$tag['slug']);
    }

    $vrfIdsByName = [];
    foreach (mh_nb_runbook_vrfs() as $vrf) {
        $res = mh_nb_ensure_vrf($baseUrl, $token, $write, $vrf);
        if (($res['ok'] ?? false) === true && (int)($res['id'] ?? 0) > 0) {
            $vrfIdsByName[(string)$vrf['name']] = (int)$res['id'];
        }
        $out['vrfs'][] = $res;
    }
    foreach (mh_nb_runbook_prefixes() as $prefix) {
        $out['prefixes'][] = mh_nb_ensure_prefix($baseUrl, $token, $write, $prefix, $vrfIdsByName);
    }

    return $out;
}

function mh_nb_bootstrap_cli_main(): void {
    global $argv;

    $args = is_array($argv ?? null) ? $argv : [];
    array_shift($args);

    $write = false;
    foreach ($args as $a) {
        $a = (string)$a;
        if ($a === '--write') {
            $write = true;
            continue;
        }
        if ($a === '--dry-run') {
            $write = false;
            continue;
        }
        if ($a === '--help' || $a === '-h') mh_nb_bootstrap_usage();
        mh_nb_bootstrap_usage();
    }

    $baseUrl = mh_nb_env('NETBOX_URL');
    $token = mh_nb_env('NETBOX_TOKEN');
    if ($baseUrl === '' || $token === '') mh_nb_bootstrap_usage();

    $out = mh_nb_ensure_runbook_schema(mh_nb_api_base_url($baseUrl), $token, $write);
    fwrite(STDOUT, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

if (PHP_SAPI === 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    mh_nb_bootstrap_cli_main();
}
