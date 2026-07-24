<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_DISABLE_AUTO_LAYOUT')) define('CUE_DISABLE_AUTO_LAYOUT', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/auth/kripz_gate.php';
mh_kripz_require('enterprise_monitor', false);

function infra_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function infra_read_json_file(string $path): ?array {
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function infra_sorted_json_files(string $dir): array {
    if (!is_dir($dir)) return [];
    $items = @scandir($dir);
    if (!is_array($items)) return [];
    $files = [];
    foreach ($items as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = rtrim($dir, '/') . '/' . $name;
        if (is_file($path) && substr($name, -5) === '.json') {
            $files[] = $path;
        }
    }
    sort($files);
    return $files;
}

function infra_latest_snapshot_map(string $reportsDir): array {
    if (!is_dir($reportsDir)) return [];
    $items = @scandir($reportsDir);
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $host) {
        if ($host === '.' || $host === '..') continue;
        $hostDir = rtrim($reportsDir, '/') . '/' . $host;
        if (!is_dir($hostDir)) continue;
        $files = infra_sorted_json_files($hostDir);
        if ($files === []) continue;
        $latest = $files[count($files) - 1];
        $data = infra_read_json_file($latest);
        if (!is_array($data)) continue;
        $out[$host] = ['path' => $latest, 'snapshot' => $data];
    }
    ksort($out);
    return $out;
}

function infra_latest_drift_report(string $dir): ?array {
    $files = infra_sorted_json_files($dir);
    if ($files === []) return null;
    $latest = $files[count($files) - 1];
    $data = infra_read_json_file($latest);
    if (!is_array($data)) return null;
    return ['path' => $latest, 'report' => $data];
}

function infra_is_private_ipv4(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
    $long = ip2long($ip);
    if (!is_int($long)) return false;
    $ranges = [
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['127.0.0.0', '127.255.255.255'],
    ];
    foreach ($ranges as [$a, $b]) {
        $al = ip2long($a);
        $bl = ip2long($b);
        if (is_int($al) && is_int($bl) && $long >= $al && $long <= $bl) return true;
    }
    return false;
}

function infra_is_private_ipv6(string $ip): bool {
    $ip = strtolower(trim($ip));
    if ($ip === '::1') return true;
    return str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd') || str_starts_with($ip, 'fe80:');
}

function infra_host_part_from_local(string $local): string {
    $local = trim($local);
    if ($local === '') return '';
    if (preg_match('/^\[(.+)\]:(\d+)$/', $local, $m)) return trim((string)$m[1]);
    $pos = strrpos($local, ':');
    if ($pos === false) return $local;
    return trim(substr($local, 0, $pos));
}

function infra_port_number_from_local(string $local): ?int {
    if (!preg_match('/:(\d+)$/', trim($local), $m)) return null;
    $port = (int)$m[1];
    return ($port > 0 && $port <= 65535) ? $port : null;
}

function infra_classify_bind_host(string $host): string {
    $host = trim($host, '[] ');
    if ($host === '' || $host === '*') return 'wildcard';
    if ($host === '127.0.0.1' || $host === '::1' || strcasecmp($host, 'localhost') === 0) return 'loopback';
    if (infra_is_private_ipv4($host) || infra_is_private_ipv6($host)) return 'private';
    if ($host === '0.0.0.0' || $host === '::') return 'wildcard';
    return 'public';
}

function infra_pick_ip_groups(array $snapshot): array {
    $public = [];
    $private = [];
    foreach ((array)($snapshot['network']['addresses'] ?? []) as $row) {
        if (!is_array($row) || (int)($row['family'] ?? 0) !== 4) continue;
        $cidr = trim((string)($row['cidr'] ?? ''));
        if ($cidr === '') continue;
        $ip = trim(explode('/', $cidr)[0] ?? '');
        if ($ip === '') continue;
        if (infra_is_private_ipv4($ip)) {
            $private[$ip] = true;
        } else {
            $public[$ip] = true;
        }
    }
    ksort($public);
    ksort($private);
    return ['public' => array_keys($public), 'private' => array_keys($private)];
}

function infra_expected_ports(): array {
    $publicProfiles = [
        'local' => [
            'tcp:21','tcp:22','tcp:25','tcp:53','tcp:80','tcp:110','tcp:111','tcp:143','tcp:443','tcp:465','tcp:587',
            'tcp:993','tcp:995','tcp:19091','tcp:19999','tcp:20048','tcp:2049','tcp:2077','tcp:2078','tcp:2079',
            'tcp:2080','tcp:2082','tcp:2083','tcp:2086','tcp:2087','tcp:2091','tcp:2095','tcp:2096','tcp:3010',
            'tcp:38177','tcp:4190','tcp:4222','tcp:42421','tcp:45451','tcp:4789','tcp:59645','tcp:7474','tcp:7687',
            'tcp:7880','tcp:7881','tcp:8090','tcp:8222','tcp:8223','tcp:8445','tcp:8787',
            'udp:111','udp:20048','udp:35766','udp:37607','udp:43380','udp:4789','udp:48836','udp:53',
        ],
        'metahumans.one' => [
            'tcp:21','tcp:22','tcp:25','tcp:53','tcp:80','tcp:110','tcp:111','tcp:143','tcp:443','tcp:465','tcp:587',
            'tcp:993','tcp:995','tcp:19091','tcp:19999','tcp:20048','tcp:2049','tcp:2077','tcp:2078','tcp:2079',
            'tcp:2080','tcp:2082','tcp:2083','tcp:2086','tcp:2087','tcp:2091','tcp:2095','tcp:2096','tcp:3010',
            'tcp:38177','tcp:4190','tcp:4222','tcp:42421','tcp:45451','tcp:4789','tcp:59645','tcp:7474','tcp:7687',
            'tcp:7880','tcp:7881','tcp:8090','tcp:8222','tcp:8223','tcp:8445','tcp:8787',
            'udp:111','udp:20048','udp:35766','udp:37607','udp:43380','udp:4789','udp:48836','udp:53',
        ],
        'superhumans.one' => [
            'tcp:21','tcp:22','tcp:53','tcp:80','tcp:111','tcp:443','tcp:4789','tcp:7880','tcp:7881','tcp:8011',
            'tcp:8080','tcp:8081','tcp:8089','tcp:9020','tcp:9091','tcp:9231','tcp:10250','tcp:11434',
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

    $profiles = [];
    foreach ($publicProfiles as $host => $ports) {
        $profiles[$host] = [];
        foreach ($ports as $entry) {
            [$proto, $port] = explode(':', $entry, 2);
            $profiles[$host][] = [
                'service' => $entry,
                'port' => (int)$port,
                'proto' => $proto,
                'exposure' => 'public',
            ];
        }
    }

    $profiles['metahumans.one'] = array_merge($profiles['metahumans.one'], [
        ['service' => 'redis', 'port' => 6379, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'mariadb (whm)', 'port' => 3306, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'mariadb (block)', 'port' => 3307, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'qdrant', 'port' => 6333, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'qdrant (grpc)', 'port' => 6334, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'g0dm0d3 api', 'port' => 7860, 'proto' => 'tcp', 'exposure' => 'internal'],
    ]);
    $profiles['local'] = array_merge($profiles['local'], [
        ['service' => 'redis', 'port' => 6379, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'mariadb (whm)', 'port' => 3306, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'mariadb (block)', 'port' => 3307, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'qdrant', 'port' => 6333, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'qdrant (grpc)', 'port' => 6334, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'g0dm0d3 api', 'port' => 7860, 'proto' => 'tcp', 'exposure' => 'internal'],
    ]);

    $profiles['superhumans.one'] = array_merge($profiles['superhumans.one'], [
        ['service' => 'tock', 'port' => 8000, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'agentic-stack (mission-control)', 'port' => 3132, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'nats', 'port' => 9222, 'proto' => 'tcp', 'exposure' => 'internal'],
        ['service' => 'hermes (v1)', 'port' => 30405, 'proto' => 'tcp', 'exposure' => 'internal'],
    ]);

    return $profiles;
}

function infra_role_for_host(string $host): string {
    $host = strtolower(trim($host));
    if (str_starts_with($host, 'rke-cp-')) return 'control-plane';
    if (str_starts_with($host, 'ingress.')) return 'ingress';
    if (str_starts_with($host, 'api.')) return 'api';
    if ($host === 'superbrains.one') return 'ai / gpu';
    if ($host === 'superhumans.one') return 'ai / realtime';
    if ($host === 'metahumans.one') return 'primary platform';
    if ($host === 'local') return 'local collector';
    return 'host';
}

function infra_format_age(?int $seconds): string {
    if (!is_int($seconds) || $seconds < 0) return 'unknown';
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return (int)floor($seconds / 60) . 'm';
    if ($seconds < 86400) return (int)floor($seconds / 3600) . 'h';
    return (int)floor($seconds / 86400) . 'd';
}

function infra_exposure_summary(string $host, array $snapshot, array $expectedMap): array {
    $observed = [];
    $publicPorts = [];
    $loopback = 0;
    $private = 0;
    $wildcard = 0;
    $public = 0;

    foreach ((array)($snapshot['ports']['listening'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $proto = strtolower(trim((string)($row['proto'] ?? '')));
        $local = trim((string)($row['local'] ?? ''));
        if ($proto === '' || $local === '') continue;
        $port = infra_port_number_from_local($local);
        if ($port === null) continue;
        $bindHost = infra_host_part_from_local($local);
        $class = infra_classify_bind_host($bindHost);
        $key = $proto . ':' . $port;
        $observed[$key] = true;
        if ($class === 'loopback') {
            $loopback++;
        } elseif ($class === 'private') {
            $private++;
        } elseif ($class === 'wildcard') {
            $wildcard++;
            $publicPorts[$key] = true;
        } else {
            $public++;
            $publicPorts[$key] = true;
        }
    }

    $expectedPublic = [];
    $expectedInternal = [];
    foreach (($expectedMap[$host] ?? []) as $row) {
        $proto = strtolower(trim((string)($row['proto'] ?? 'tcp')));
        $port = (int)($row['port'] ?? 0);
        if ($port <= 0) continue;
        $key = $proto . ':' . $port;
        if (($row['exposure'] ?? 'public') === 'public') {
            $expectedPublic[$key] = (string)($row['service'] ?? $key);
        } else {
            $expectedInternal[$key] = (string)($row['service'] ?? $key);
        }
    }

    $unexpectedPublic = [];
    foreach ($publicPorts as $key => $_) {
        if ($expectedPublic !== []) {
            if (!isset($expectedPublic[$key])) $unexpectedPublic[] = $key;
        } else {
            $unexpectedPublic[] = $key;
        }
    }
    sort($unexpectedPublic);

    $missingExpectedPublic = [];
    foreach (array_keys($expectedPublic) as $key) {
        if (!isset($observed[$key])) $missingExpectedPublic[] = $key;
    }
    sort($missingExpectedPublic);

    return [
        'counts' => [
            'total' => count($observed),
            'public' => count($publicPorts),
            'private' => $private,
            'loopback' => $loopback,
            'wildcard' => $wildcard,
        ],
        'unexpected_public' => $unexpectedPublic,
        'missing_expected_public' => $missingExpectedPublic,
        'headline' => 'public ' . count($publicPorts) . ' · private ' . $private . ' · loopback ' . $loopback,
    ];
}

function infra_host_health(array $snapshot, ?array $drift, array $exposure): array {
    $observedAt = isset($snapshot['observed_at']) ? strtotime((string)$snapshot['observed_at']) : false;
    $age = is_int($observedAt) ? max(0, time() - $observedAt) : null;
    $reasons = [];
    $level = 'ok';

    if (!is_int($age)) {
        $level = 'critical';
        $reasons[] = 'snapshot time unknown';
    } elseif ($age > 7 * 86400) {
        $level = 'critical';
        $reasons[] = 'snapshot stale';
    } elseif ($age > 2 * 86400) {
        $level = 'warning';
        $reasons[] = 'snapshot aging';
    }

    $driftCounts = is_array($drift['counts'] ?? null) ? $drift['counts'] : [];
    $driftTotal = 0;
    foreach (['changes', 'addresses_added', 'addresses_removed', 'mounts_added', 'mounts_removed', 'ports_added', 'ports_removed'] as $key) {
        $driftTotal += (int)($driftCounts[$key] ?? 0);
    }
    if ($driftTotal > 0 && $level !== 'critical') {
        $level = 'warning';
        $reasons[] = 'drift detected';
    }

    if (!empty($exposure['unexpected_public'])) {
        $level = 'critical';
        $reasons[] = 'unexpected public port';
    } elseif (!empty($exposure['missing_expected_public']) && $level === 'ok') {
        $level = 'warning';
        $reasons[] = 'expected public port missing';
    }

    if ($reasons === []) $reasons[] = 'healthy';

    return [
        'level' => $level,
        'age_s' => $age,
        'age_human' => infra_format_age($age),
        'summary' => implode(' · ', $reasons),
    ];
}

function infra_monitoring_summary(?array $monitoring): array {
    $checks = is_array($monitoring['checks'] ?? null) ? $monitoring['checks'] : [];
    $failing = [];
    foreach ($checks as $name => $row) {
        if (!is_array($row)) continue;
        if (($row['ok'] ?? null) === false) $failing[] = (string)$name;
    }
    sort($failing);
    return [
        'ok' => (bool)($monitoring['ok'] ?? false),
        'failing' => $failing,
        'timestamp' => isset($monitoring['ts']) ? (int)$monitoring['ts'] : null,
    ];
}

function infra_sync_summary(?array $sync): array {
    if (!is_array($sync)) {
        return ['ok' => false, 'summary' => 'missing sync status', 'last_sync' => null];
    }
    $components = is_array($sync['components'] ?? null) ? $sync['components'] : [];
    $bad = [];
    foreach ($components as $name => $row) {
        if (!is_array($row)) continue;
        if (($row['status'] ?? '') !== 'synced') $bad[] = (string)$name;
    }
    sort($bad);
    return [
        'ok' => $bad === [],
        'summary' => $bad === [] ? 'all synced' : ('attention: ' . implode(', ', $bad)),
        'last_sync' => isset($sync['last_sync']) ? (string)$sync['last_sync'] : null,
    ];
}

function infra_tools_catalog(): array {
    return [
        ['label' => 'Infra Dashboard', 'href' => '/gear/settings/infra.php', 'desc' => 'Unified hosts, IPs, ports, health, drift, and exposure view.'],
        ['label' => 'Enterprise Monitor', 'href' => '/gear/settings/enterprise_monitor.php', 'desc' => 'Live HTTP, TCP, SSH, storage, Netdata, and alert/log checks.'],
        ['label' => 'Ports Verification', 'href' => '/gear/settings/ports.php', 'desc' => 'Expected service exposure, local listeners, reachability scans, and bind-policy issues.'],
        ['label' => 'DB Manager Monitor', 'href' => '/gear/settings/dbmanager_monitor.php', 'desc' => 'Database config, monitoring runner, backup runner, and guard scan status.'],
        ['label' => 'Global UI Sync', 'href' => '/gear/sync/index.php', 'desc' => 'Sync component status and JSON-to-database synchronization health.'],
        ['label' => 'Backup Validate Restore', 'href' => '/gear/backups/validate-restore.php', 'desc' => 'Database, vector, graph, and tenant restore validation checks.'],
        ['label' => 'KYC Ops Health', 'href' => '/auth/id/health.php', 'desc' => 'Identity/KYC ops health and environment flags.'],
        ['label' => 'Identifier Rules', 'href' => '/gear/settings/id_identifiers.php', 'desc' => 'Protected identifier policy and auth-side verification surface.'],
        ['label' => 'Tool Hub', 'href' => '/hub/tools/index.php', 'desc' => 'General operational dashboard launcher surface.'],
    ];
}

function infra_build_dashboard(): array {
    $base = '/home/onemeta/public_html/gear/infra/drift';
    $reportsDir = $base . '/reports';
    $driftDir = $base . '/drift-reports';
    $monitoring = infra_read_json_file('/home/onemeta/data/logs/monitoring_status.json');
    $backup = infra_read_json_file('/home/onemeta/data/logs/backup_status.json');
    $sync = infra_read_json_file('/home/onemeta/data/sync/sync-status.json');
    $latestDrift = infra_latest_drift_report($driftDir);
    $driftHosts = is_array($latestDrift['report']['hosts'] ?? null) ? $latestDrift['report']['hosts'] : [];
    $snapshots = infra_latest_snapshot_map($reportsDir);
    $expected = infra_expected_ports();

    $hosts = [];
    $healthCounts = ['ok' => 0, 'warning' => 0, 'critical' => 0];
    $driftAlerts = 0;
    $exposureAlerts = 0;

    foreach ($snapshots as $host => $row) {
        $snapshot = (array)$row['snapshot'];
        $ips = infra_pick_ip_groups($snapshot);
        $drift = isset($driftHosts[$host]) && is_array($driftHosts[$host]) ? $driftHosts[$host] : null;
        $exposure = infra_exposure_summary($host, $snapshot, $expected);
        $health = infra_host_health($snapshot, $drift, $exposure);
        $healthCounts[$health['level']]++;
        if (is_array($drift)) {
            $counts = is_array($drift['counts'] ?? null) ? $drift['counts'] : [];
            $driftTotal = 0;
            foreach ($counts as $v) $driftTotal += (int)$v;
            if ($driftTotal > 0) $driftAlerts++;
        }
        if (!empty($exposure['unexpected_public'])) $exposureAlerts++;

        $ports = [];
        foreach ((array)($snapshot['ports']['listening'] ?? []) as $portRow) {
            if (!is_array($portRow)) continue;
            $proto = strtolower(trim((string)($portRow['proto'] ?? '')));
            $local = trim((string)($portRow['local'] ?? ''));
            $port = infra_port_number_from_local($local);
            if ($proto === '' || $port === null) continue;
            $ports[] = $proto . ':' . $port;
        }
        $ports = array_values(array_unique($ports));
        sort($ports);

        $hosts[] = [
            'host' => $host,
            'role' => infra_role_for_host($host),
            'observed_at' => (string)($snapshot['observed_at'] ?? ''),
            'health' => $health,
            'ips' => $ips,
            'ports' => [
                'count' => count($ports),
                'sample' => array_slice($ports, 0, 10),
            ],
            'drift' => [
                'counts' => is_array($drift['counts'] ?? null) ? $drift['counts'] : [],
                'summary' => is_array($drift)
                    ? ('changes ' . (int)($drift['counts']['changes'] ?? 0) .
                        ' · ports +' . (int)($drift['counts']['ports_added'] ?? 0) .
                        '/-' . (int)($drift['counts']['ports_removed'] ?? 0))
                    : 'no drift report',
            ],
            'exposure' => $exposure,
            'hardware' => [
                'ram_gb' => isset($snapshot['ram_gb']) ? (int)$snapshot['ram_gb'] : null,
                'gpu_count' => isset($snapshot['gpu']['count']) ? (int)$snapshot['gpu']['count'] : 0,
                'gpu_model' => isset($snapshot['gpu']['model']) ? (string)$snapshot['gpu']['model'] : '',
            ],
        ];
    }

    usort($hosts, static fn(array $a, array $b) => strcmp((string)$a['host'], (string)$b['host']));

    $monitorSummary = infra_monitoring_summary($monitoring);
    $backupOk = is_array($backup) ? (bool)($backup['ok'] ?? false) : false;
    $syncSummary = infra_sync_summary($sync);

    return [
        'generated_at' => gmdate('c'),
        'overview' => [
            'hosts' => count($hosts),
            'drift_alerts' => $driftAlerts,
            'exposure_alerts' => $exposureAlerts,
            'health_counts' => $healthCounts,
        ],
        'monitoring' => $monitorSummary,
        'backup' => [
            'ok' => $backupOk,
            'timestamp' => is_array($backup) && isset($backup['ts']) ? (int)$backup['ts'] : null,
            'ran' => is_array($backup['ran'] ?? null) ? $backup['ran'] : [],
            'skipped' => is_array($backup['skipped'] ?? null) ? $backup['skipped'] : [],
        ],
        'sync' => $syncSummary,
        'data_sources' => [
            'snapshots_dir' => $reportsDir,
            'drift_report_path' => is_array($latestDrift) ? (string)$latestDrift['path'] : '',
            'monitoring_path' => '/home/onemeta/data/logs/monitoring_status.json',
            'backup_path' => '/home/onemeta/data/logs/backup_status.json',
            'sync_path' => '/home/onemeta/data/sync/sync-status.json',
        ],
        'hosts' => $hosts,
        'tools' => infra_tools_catalog(),
        'monitoring_tools_found' => [
            ['path' => '/gear/settings/enterprise_monitor.php', 'purpose' => 'live enterprise monitoring dashboard'],
            ['path' => '/gear/settings/ports.php', 'purpose' => 'exposure verification and listener analysis'],
            ['path' => '/gear/settings/dbmanager_monitor.php', 'purpose' => 'database manager and ops status monitor'],
            ['path' => '/gear/settings/dbmanager_monitor_api.php', 'purpose' => 'JSON aggregate status API'],
            ['path' => '/gear/sync/index.php', 'purpose' => 'sync status writer and portal'],
            ['path' => '/gear/backups/validate-restore.php', 'purpose' => 'backup and restore validation health page'],
            ['path' => '/auth/id/health.php', 'purpose' => 'identity and KYC ops health'],
            ['path' => '/hub/tools/index.php', 'purpose' => 'operational dashboard launcher'],
            ['path' => '/home/onemeta/ops/monitoring/mh_monitor.php', 'purpose' => 'cron health snapshot and alerts writer'],
            ['path' => '/home/onemeta/ops/backup/mh_backup.php', 'purpose' => 'backup runner status writer'],
        ],
    ];
}

$dashboard = infra_build_dashboard();
$wantJson = false;
if (isset($_GET['format']) && is_string($_GET['format']) && strtolower(trim((string)$_GET['format'])) === 'json') {
    $wantJson = true;
} elseif (isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $wantJson = true;
}
if ($wantJson) {
    infra_json(['success' => true, 'dashboard' => $dashboard]);
}

$overview = (array)($dashboard['overview'] ?? []);
$monitoring = (array)($dashboard['monitoring'] ?? []);
$backup = (array)($dashboard['backup'] ?? []);
$sync = (array)($dashboard['sync'] ?? []);
$hosts = is_array($dashboard['hosts'] ?? null) ? $dashboard['hosts'] : [];
$tools = is_array($dashboard['tools'] ?? null) ? $dashboard['tools'] : [];
$foundTools = is_array($dashboard['monitoring_tools_found'] ?? null) ? $dashboard['monitoring_tools_found'] : [];

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Meta Humans Infra</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-infra-wrap { max-width: 1280px; margin: 0 auto; }
        .mh-infra-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .mh-infra-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 16px; overflow: hidden; }
        .mh-infra-title { margin: 0 0 8px 0; color: #e6f6ff; }
        .mh-infra-muted { color: #9aa; font-size: 12px; }
        .mh-infra-kpi { font-size: 28px; font-weight: 800; color: #fff; margin-top: 10px; }
        .mh-infra-ok { color: #a6f3c6; }
        .mh-infra-warn { color: #ffd27a; }
        .mh-infra-bad { color: #ffb3b3; }
        .mh-infra-badge { display: inline-flex; align-items: center; gap: 8px; border-radius: 999px; padding: 4px 10px; font-size: 12px; font-weight: 700; border: 1px solid rgba(255,255,255,0.16); }
        .mh-infra-badge-ok { background: rgba(16,185,129,0.16); border-color: rgba(16,185,129,0.35); color: #c8ffe8; }
        .mh-infra-badge-warn { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.35); color: #ffe7bf; }
        .mh-infra-badge-bad { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.35); color: #ffd0d0; }
        .mh-infra-table { width: 100%; border-collapse: collapse; }
        .mh-infra-table th, .mh-infra-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.08); vertical-align: top; }
        .mh-infra-table th { color: #00d4ff; font-weight: 700; }
        .mh-infra-links { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .mh-infra-link { display: block; text-decoration: none; color: inherit; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 14px; background: rgba(0,0,0,0.18); }
        .mh-infra-link:hover { border-color: rgba(0,212,255,0.35); }
        .mh-infra-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
        .mh-infra-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top: 12px; }
        .mh-infra-btn { display:inline-block; text-decoration:none; padding:10px 12px; border-radius:12px; border:1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; font-weight:700; }
        .mh-infra-btn:hover { border-color: rgba(0,212,255,0.45); }
        @media (max-width: 1180px) { .mh-infra-grid { grid-template-columns: repeat(2, 1fr); } .mh-infra-links { grid-template-columns: 1fr; } }
        @media (max-width: 760px) { .mh-infra-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <section style="padding: 26px 0;">
        <div class="container mh-infra-wrap">
            <h1 style="margin:0 0 8px 0;">Meta Humans Infra</h1>
            <div class="mh-infra-muted">Single view for hosts, roles, public/private IPs, listening ports, health, drift, and exposure using the existing monitoring and inventory framework.</div>
            <div class="mh-infra-actions">
                <a class="mh-infra-btn" href="/gear/settings/infra.php">Refresh</a>
                <a class="mh-infra-btn" href="/gear/settings/infra.php?format=json">Open JSON</a>
                <a class="mh-infra-btn" href="/gear/settings/enterprise_monitor.php">Enterprise Monitor</a>
                <a class="mh-infra-btn" href="/gear/settings/ports.php">Ports Verification</a>
            </div>

            <div style="height: 16px;"></div>
            <div class="mh-infra-grid">
                <div class="mh-infra-card">
                    <div class="mh-infra-muted">Hosts observed</div>
                    <div class="mh-infra-kpi"><?php echo (int)($overview['hosts'] ?? 0); ?></div>
                </div>
                <div class="mh-infra-card">
                    <div class="mh-infra-muted">Monitoring health</div>
                    <div class="mh-infra-kpi <?php echo !empty($monitoring['ok']) ? 'mh-infra-ok' : 'mh-infra-bad'; ?>"><?php echo !empty($monitoring['ok']) ? 'OK' : 'ATTN'; ?></div>
                    <div class="mh-infra-muted"><?php echo !empty($monitoring['failing']) ? htmlspecialchars(implode(', ', (array)$monitoring['failing']), ENT_QUOTES) : 'No failing checks'; ?></div>
                </div>
                <div class="mh-infra-card">
                    <div class="mh-infra-muted">Drift alerts</div>
                    <div class="mh-infra-kpi <?php echo ((int)($overview['drift_alerts'] ?? 0) > 0) ? 'mh-infra-warn' : 'mh-infra-ok'; ?>"><?php echo (int)($overview['drift_alerts'] ?? 0); ?></div>
                    <div class="mh-infra-muted">Hosts with observed changes in the latest drift report.</div>
                </div>
                <div class="mh-infra-card">
                    <div class="mh-infra-muted">Exposure alerts</div>
                    <div class="mh-infra-kpi <?php echo ((int)($overview['exposure_alerts'] ?? 0) > 0) ? 'mh-infra-bad' : 'mh-infra-ok'; ?>"><?php echo (int)($overview['exposure_alerts'] ?? 0); ?></div>
                    <div class="mh-infra-muted">Hosts with unexpected public-facing ports.</div>
                </div>
            </div>

            <div style="height: 16px;"></div>
            <div class="mh-infra-grid" style="grid-template-columns: repeat(3, 1fr);">
                <div class="mh-infra-card">
                    <h2 class="mh-infra-title">Status Writers</h2>
                    <div><span class="mh-infra-badge <?php echo !empty($monitoring['ok']) ? 'mh-infra-badge-ok' : 'mh-infra-badge-bad'; ?>">Monitoring <?php echo !empty($monitoring['ok']) ? 'OK' : 'ATTN'; ?></span></div>
                    <div style="height:8px;"></div>
                    <div><span class="mh-infra-badge <?php echo !empty($backup['ok']) ? 'mh-infra-badge-ok' : 'mh-infra-badge-bad'; ?>">Backup <?php echo !empty($backup['ok']) ? 'OK' : 'ATTN'; ?></span></div>
                    <div style="height:8px;"></div>
                    <div><span class="mh-infra-badge <?php echo !empty($sync['ok']) ? 'mh-infra-badge-ok' : 'mh-infra-badge-warn'; ?>">Sync <?php echo !empty($sync['ok']) ? 'OK' : 'ATTN'; ?></span></div>
                    <div class="mh-infra-muted" style="margin-top: 10px;">Backup skipped: <?php echo htmlspecialchars(implode(', ', (array)($backup['skipped'] ?? [])), ENT_QUOTES); ?></div>
                    <div class="mh-infra-muted">Sync: <?php echo htmlspecialchars((string)($sync['summary'] ?? 'unknown'), ENT_QUOTES); ?></div>
                </div>
                <div class="mh-infra-card">
                    <h2 class="mh-infra-title">Health Mix</h2>
                    <div class="mh-infra-muted">OK: <?php echo (int)(($overview['health_counts']['ok'] ?? 0)); ?></div>
                    <div class="mh-infra-muted">Warning: <?php echo (int)(($overview['health_counts']['warning'] ?? 0)); ?></div>
                    <div class="mh-infra-muted">Critical: <?php echo (int)(($overview['health_counts']['critical'] ?? 0)); ?></div>
                </div>
                <div class="mh-infra-card">
                    <h2 class="mh-infra-title">Data Sources</h2>
                    <div class="mh-infra-code"><?php echo htmlspecialchars((string)($dashboard['data_sources']['snapshots_dir'] ?? ''), ENT_QUOTES); ?></div>
                    <div class="mh-infra-code" style="margin-top:8px;"><?php echo htmlspecialchars((string)($dashboard['data_sources']['drift_report_path'] ?? ''), ENT_QUOTES); ?></div>
                </div>
            </div>

            <div style="height: 16px;"></div>
            <div class="mh-infra-card">
                <h2 class="mh-infra-title">Hosts</h2>
                <div class="mh-infra-muted">Latest SSH snapshot per host, latest drift report, and ports exposure rules.</div>
                <div style="overflow:auto; margin-top: 12px;">
                    <table class="mh-infra-table">
                        <thead>
                            <tr>
                                <th>Host</th>
                                <th>Role</th>
                                <th>Health</th>
                                <th>Public / Private IPs</th>
                                <th>Listening Ports</th>
                                <th>Drift</th>
                                <th>Exposure</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hosts as $host): ?>
                                <?php
                                    $health = (array)($host['health'] ?? []);
                                    $level = (string)($health['level'] ?? 'warning');
                                    $badgeClass = $level === 'ok' ? 'mh-infra-badge-ok' : ($level === 'critical' ? 'mh-infra-badge-bad' : 'mh-infra-badge-warn');
                                    $ips = (array)($host['ips'] ?? []);
                                    $ports = (array)($host['ports'] ?? []);
                                    $drift = (array)($host['drift'] ?? []);
                                    $exposure = (array)($host['exposure'] ?? []);
                                ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string)$host['host'], ENT_QUOTES); ?></div>
                                        <div class="mh-infra-muted"><?php echo htmlspecialchars((string)($host['observed_at'] ?? ''), ENT_QUOTES); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)($host['role'] ?? ''), ENT_QUOTES); ?></div>
                                        <div class="mh-infra-muted">
                                            RAM <?php echo htmlspecialchars((string)($host['hardware']['ram_gb'] ?? '?'), ENT_QUOTES); ?> GB
                                            <?php if (!empty($host['hardware']['gpu_count'])): ?>
                                                · GPU <?php echo (int)$host['hardware']['gpu_count']; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="mh-infra-badge <?php echo $badgeClass; ?>"><?php echo strtoupper($level); ?></span>
                                        <div class="mh-infra-muted" style="margin-top:8px;"><?php echo htmlspecialchars((string)($health['summary'] ?? ''), ENT_QUOTES); ?></div>
                                        <div class="mh-infra-muted">Age <?php echo htmlspecialchars((string)($health['age_human'] ?? 'unknown'), ENT_QUOTES); ?></div>
                                    </td>
                                    <td>
                                        <div><strong>Public:</strong> <?php echo htmlspecialchars(implode(', ', (array)($ips['public'] ?? [])) ?: 'none', ENT_QUOTES); ?></div>
                                        <div class="mh-infra-muted"><strong>Private:</strong> <?php echo htmlspecialchars(implode(', ', (array)($ips['private'] ?? [])) ?: 'none', ENT_QUOTES); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo (int)($ports['count'] ?? 0); ?> total</div>
                                        <div class="mh-infra-muted"><?php echo htmlspecialchars(implode(', ', (array)($ports['sample'] ?? [])), ENT_QUOTES); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)($drift['summary'] ?? 'no drift'), ENT_QUOTES); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars((string)($exposure['headline'] ?? ''), ENT_QUOTES); ?></div>
                                        <div class="mh-infra-muted">
                                            unexpected public: <?php echo htmlspecialchars(implode(', ', (array)($exposure['unexpected_public'] ?? [])) ?: 'none', ENT_QUOTES); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="height: 16px;"></div>
            <div class="mh-infra-links">
                <div class="mh-infra-card">
                    <h2 class="mh-infra-title">Operational Tools</h2>
                    <div class="mh-infra-links">
                        <?php foreach ($tools as $tool): ?>
                            <a class="mh-infra-link" href="<?php echo htmlspecialchars((string)$tool['href'], ENT_QUOTES); ?>">
                                <div style="font-weight:700;"><?php echo htmlspecialchars((string)$tool['label'], ENT_QUOTES); ?></div>
                                <div class="mh-infra-muted" style="margin-top:6px;"><?php echo htmlspecialchars((string)$tool['desc'], ENT_QUOTES); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mh-infra-card">
                    <h2 class="mh-infra-title">Monitoring Tools Found</h2>
                    <table class="mh-infra-table">
                        <thead>
                            <tr>
                                <th>Path</th>
                                <th>Purpose</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($foundTools as $tool): ?>
                                <tr>
                                    <td class="mh-infra-code"><?php echo htmlspecialchars((string)$tool['path'], ENT_QUOTES); ?></td>
                                    <td><?php echo htmlspecialchars((string)$tool['purpose'], ENT_QUOTES); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</main>
<?php
if (function_exists('renderGlobalFooter')) {
    renderGlobalFooter(['ftr_position' => 'bottom', 'ftr_auto_offset' => false]);
}
if (function_exists('renderGlobalWidgets')) {
    renderGlobalWidgets();
} elseif (function_exists('renderGlobalStatusBar')) {
    renderGlobalStatusBar();
}
if (function_exists('includeGlobalUIScripts')) {
    includeGlobalUIScripts();
}
?>
</body>
</html>
