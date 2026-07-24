<?php
/**
 * Global UI Sync Management Portal
 * CUE Framework Compliant - JSON to Database Synchronization System
 * 
 * @package CUE Framework
 * @version 100.0.99
 */

if (PHP_SAPI === 'cli') {
    $args = $argv ?? [];
    $args = is_array($args) ? $args : [];

    $has = function (string $flag) use ($args): bool {
        return in_array($flag, $args, true);
    };

    $dataDir = '/data';

    if ($has('--tmp-cleanup')) {
        $now = time();
        $minAge = 2 * 86400;
        $tmp = '/tmp';
        $stats = ['ok' => true, 'deleted' => 0, 'bytes' => 0, 'paths' => []];

        $rm_rf = function (string $path) use (&$stats): void {
            if ($path === '' || $path === '/' || $path === '/tmp' || $path === '/var' || $path === '/home') return;
            if (!file_exists($path) && !is_link($path)) return;
            if (is_link($path) || is_file($path)) {
                $sz = @filesize($path);
                if (@unlink($path)) {
                    $stats['deleted']++;
                    $stats['bytes'] += is_int($sz) ? $sz : 0;
                }
                return;
            }
            if (is_dir($path)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $item) {
                    $p = $item->getPathname();
                    if ($item->isLink() || $item->isFile()) {
                        $sz = $item->getSize();
                        if (@unlink($p)) {
                            $stats['deleted']++;
                            $stats['bytes'] += is_int($sz) ? $sz : 0;
                        }
                    } elseif ($item->isDir()) {
                        @rmdir($p);
                    }
                }
                @rmdir($path);
            }
        };

        $patterns = [
            $tmp . '/meta-humans-theia_*.tar',
            $tmp . '/metahumans-docs.tgz',
            $tmp . '/stirling-app.jar',
            $tmp . '/sql*',
            $tmp . '/webpack*_stats.json',
            $tmp . '/wstats.json',
            $tmp . '/webpack_verbose_user.log',
        ];
        foreach ($patterns as $pat) {
            $hits = glob($pat);
            if (!is_array($hits)) continue;
            foreach ($hits as $p) {
                $p = (string)$p;
                if ($p === '' || !is_file($p)) continue;
                $mt = @filemtime($p);
                if (!is_int($mt) || ($now - $mt) < $minAge) continue;
                $sz = @filesize($p);
                if (@unlink($p)) {
                    $stats['deleted']++;
                    $stats['bytes'] += is_int($sz) ? $sz : 0;
                    $stats['paths'][] = $p;
                }
            }
        }

        foreach ([$tmp . '/go-cache', $tmp . '/node-compile-cache'] as $d) {
            if (!is_dir($d)) continue;
            $mt = @filemtime($d);
            if (!is_int($mt) || ($now - $mt) < $minAge) continue;
            $rm_rf($d);
            $stats['paths'][] = $d;
        }

        $skipNames = ['mysql3307.sock' => true, 'mysql3307.pid' => true, 'mariadb3307.err' => true];
        $names = @scandir($tmp);
        if (is_array($names)) {
            foreach ($names as $n) {
                $n = (string)$n;
                if ($n === '' || $n === '.' || $n === '..') continue;
                if (isset($skipNames[$n])) continue;
                if (str_starts_with($n, 'systemd-private-')) continue;
                $p = $tmp . '/' . $n;
                if (!is_file($p)) continue;
                $mt = @filemtime($p);
                if (!is_int($mt) || ($now - $mt) < $minAge) continue;
                $sz = @filesize($p);
                if (!is_int($sz) || $sz < 50 * 1024 * 1024) continue;
                if (@unlink($p)) {
                    $stats['deleted']++;
                    $stats['bytes'] += $sz;
                    $stats['paths'][] = $p;
                }
            }
        }

        echo json_encode($stats, JSON_UNESCAPED_SLASHES) . "\n";
        exit(($stats['ok'] ?? false) ? 0 : 2);
    }

    if ($has('--enterprise-monitor-alerts-rotate')) {
        $logPath = $dataDir . '/logs/enterprise-monitor/alerts.log';
        $dir = dirname($logPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $out = ['ok' => true, 'path' => $logPath, 'rotated' => false];
        if (!is_file($logPath)) {
            $out['created'] = (@file_put_contents($logPath, '', LOCK_EX) !== false);
            $out['ok'] = (bool)$out['created'];
            echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
            exit($out['ok'] ? 0 : 2);
        }
        $sz = @filesize($logPath);
        $sz = is_int($sz) ? $sz : 0;
        $out['size'] = $sz;
        if ($sz <= 0) {
            echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }
        $ts = gmdate('Ymd\\THis\\Z');
        $archive = $dir . '/alerts.' . $ts . '.log';
        $ok = @rename($logPath, $archive);
        if ($ok) {
            @file_put_contents($logPath, '', LOCK_EX);
        }
        $out['ok'] = $ok;
        $out['rotated'] = $ok;
        $out['archive'] = $archive;
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
        exit($ok ? 0 : 2);
    }

    if ($has('--mariadb3307-maint')) {
        $path = '/tmp/mariadb3307.err';
        if (!is_file($path)) {
            echo json_encode(['ok' => true, 'skipped' => true, 'path' => $path], JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }
        $sz = @filesize($path);
        $sz = is_int($sz) ? $sz : 0;
        $max = 5 * 1024 * 1024;
        $out = ['ok' => true, 'path' => $path, 'size' => $sz, 'truncated' => false];
        if ($sz > $max) {
            $origPerm = @fileperms($path);
            $origPerm = is_int($origPerm) ? ($origPerm & 0777) : null;
            @chmod($path, 0666);
            $ok = @file_put_contents($path, '') !== false;
            if ($origPerm !== null) {
                @chmod($path, $origPerm);
            }
            $out['ok'] = $ok;
            $out['truncated'] = $ok;
            $out['size_after'] = $ok ? 0 : $sz;
        }
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
        exit(($out['ok'] ?? false) ? 0 : 2);
    }

    if ($has('--global-ui-sync')) {
        $components = [
            'header' => $dataDir . '/global-ui/header/header-config.json',
            'footer' => $dataDir . '/global-ui/footer/footer-config.json',
            'navigation' => $dataDir . '/global-ui/navigation/menu-config.json',
            'hamburger' => $dataDir . '/global-ui/hamburger/hamburger-config.json',
            'theme' => $dataDir . '/theme/config.json',
        ];

        $now = time();
        $iso = date('c', $now);
        $statusPath = $dataDir . '/sync/sync-status.json';
        $logPath = $dataDir . '/sync/sync-log.json';

        $existing = [];
        if (is_file($statusPath)) {
            $decoded = json_decode((string)@file_get_contents($statusPath), true);
            if (is_array($decoded)) $existing = $decoded;
        }

        $componentStatus = [];
        $errors = [];
        foreach ($components as $name => $path) {
            $row = ['status' => 'missing', 'json_last_modified' => null, 'database_last_modified' => null, 'conflicts' => []];
            if (is_file($path)) {
                $mt = @filemtime($path);
                $row['json_last_modified'] = is_int($mt) ? date('c', $mt) : null;
                $raw = @file_get_contents($path);
                $parsed = is_string($raw) ? json_decode($raw, true) : null;
                if (is_array($parsed)) {
                    $row['status'] = 'synced';
                } else {
                    $row['status'] = 'invalid_json';
                    $errors[] = $name . ':invalid_json';
                }
            }
            $componentStatus[$name] = $row;
        }

        $newStatus = [
            'sync_enabled' => true,
            'last_sync' => $iso,
            'sync_direction' => (string)($existing['sync_direction'] ?? 'json_to_database'),
            'components' => $componentStatus,
            'auto_sync' => is_array($existing['auto_sync'] ?? null) ? $existing['auto_sync'] : ['enabled' => true, 'interval' => 86400, 'conflict_resolution' => 'manual'],
            'version' => (string)($existing['version'] ?? '1.0.0'),
            'database_available' => (bool)($existing['database_available'] ?? true),
        ];

        if (!is_dir(dirname($statusPath))) @mkdir(dirname($statusPath), 0755, true);
        $ok = @file_put_contents($statusPath, json_encode($newStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false;

        $log = [];
        if (is_file($logPath)) {
            $decoded = json_decode((string)@file_get_contents($logPath), true);
            if (is_array($decoded)) $log = $decoded;
        }
        if (!isset($log['entries']) || !is_array($log['entries'])) $log['entries'] = [];
        $log['entries'][] = [
            'timestamp' => date('Y-m-d H:i:s', $now),
            'component' => 'SYSTEM',
            'message' => $ok ? 'Daily Global UI sync completed' : 'Daily Global UI sync failed',
            'details' => ['components' => array_keys($components), 'errors' => $errors],
        ];
        if (!is_dir(dirname($logPath))) @mkdir(dirname($logPath), 0755, true);
        @file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

        echo json_encode(['ok' => $ok, 'status_path' => $statusPath, 'log_path' => $logPath, 'errors' => $errors], JSON_UNESCAPED_SLASHES) . "\n";
        exit($ok ? 0 : 2);
    }
}

// Load CUE Framework
if (PHP_SAPI === 'cli') {
    $isAjaxRequest = true;
    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = '/cli';
    }
}
require_once dirname(__DIR__, 2) . '/.cue/cue.php';
if (is_file(dirname(__DIR__, 2) . '/auth/tenant_provisioning.php')) {
    require_once dirname(__DIR__, 2) . '/auth/tenant_provisioning.php';
}

function mh_sync_cron_data_dir(): string {
    return function_exists('getDataPath') ? rtrim((string)getDataPath(), '/') : '/data';
}

function mh_sync_cron_cfg_path(): string {
    return mh_sync_cron_data_dir() . '/config/gear-crons.json';
}

function mh_sync_cron_state_path(): string {
    return mh_sync_cron_data_dir() . '/logs/gear_cron_state.json';
}

function mh_sync_cron_runs_path(): string {
    return mh_sync_cron_data_dir() . '/logs/gear_cron_runs.jsonl';
}

function mh_sync_backup_sets_policy(): array {
    $path = mh_sync_cron_data_dir() . '/config/backup-sets.json';
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) return [];
    return $decoded;
}

function mh_sync_backup_cron_expr(string $setId, string $frequency): string {
    $setId = trim($setId);
    $frequency = trim($frequency);
    if ($frequency === 'hourly') {
        $minute = 0;
        if ($setId === 'vector') $minute = 5;
        if ($setId === 'graph') $minute = 10;
        if ($setId === 'data') $minute = 0;
        return (string)$minute . ' * * * *';
    }
    if ($frequency === 'daily') {
        $hm = ['data' => [2, 0], 'mysql' => [2, 10], 'vector' => [2, 20], 'graph' => [2, 30]];
        $h = isset($hm[$setId]) ? (int)$hm[$setId][0] : 2;
        $m = isset($hm[$setId]) ? (int)$hm[$setId][1] : 0;
        return (string)$m . ' ' . (string)$h . ' * * *';
    }
    if ($frequency === 'weekly') {
        $hm = ['data' => [3, 0], 'mysql' => [3, 10], 'vector' => [3, 20], 'graph' => [3, 30]];
        $h = isset($hm[$setId]) ? (int)$hm[$setId][0] : 3;
        $m = isset($hm[$setId]) ? (int)$hm[$setId][1] : 0;
        return (string)$m . ' ' . (string)$h . ' * * 0';
    }
    if ($frequency === 'monthly') {
        $hm = ['data' => [4, 0], 'mysql' => [4, 10], 'vector' => [4, 20], 'graph' => [4, 30]];
        $h = isset($hm[$setId]) ? (int)$hm[$setId][0] : 4;
        $m = isset($hm[$setId]) ? (int)$hm[$setId][1] : 0;
        return (string)$m . ' ' . (string)$h . ' 1 * *';
    }
    return '0 * * * *';
}

function mh_sync_backup_jobs(): array {
    $policy = mh_sync_backup_sets_policy();
    $defaults = [
        'data' => ['frequency' => 'daily'],
        'mysql' => ['frequency' => 'hourly'],
        'vector' => ['frequency' => 'hourly'],
        'graph' => ['frequency' => 'hourly'],
    ];
    $phpBin = '/opt/cpanel/ea-php83/root/usr/bin/php';
    $script = '/home/onemeta/public_html/gear/backups/run.php';
    $jobs = [];
    foreach ($defaults as $setId => $row) {
        $freq = (string)($row['frequency'] ?? 'hourly');
        if (isset($policy[$setId]) && is_array($policy[$setId]) && isset($policy[$setId]['frequency']) && is_string($policy[$setId]['frequency'])) {
            $pFreq = trim((string)$policy[$setId]['frequency']);
            if (in_array($pFreq, ['hourly', 'daily', 'weekly', 'monthly'], true)) {
                $freq = $pFreq;
            }
        }
        $jobs['backup_' . $setId] = [
            'label' => 'Backup ' . $setId,
            'type' => 'php',
            'enabled' => true,
            'cron' => mh_sync_backup_cron_expr($setId, $freq),
            'cmd' => $phpBin . ' ' . $script . ' ' . $setId,
            'max' => 1,
            'max_seconds' => 300,
        ];
    }
    return $jobs;
}

function mh_sync_mysql_dumps_job(): array {
    $phpBin = '/opt/cpanel/ea-php83/root/usr/bin/php';
    $script = '/home/onemeta/public_html/gear/backups/mysql-dumps.php';
    return [
        'mysql_dumps_runner' => [
            'label' => 'MySQL logical dumps runner',
            'type' => 'php',
            'enabled' => true,
            'cron' => '*/10 * * * *',
            'cmd' => $phpBin . ' ' . $script,
            'max' => 1,
            'max_seconds' => 300,
        ],
    ];
}

function mh_sync_cron_load_cfg(): array {
    $path = mh_sync_cron_cfg_path();
    if (!is_file($path)) {
        $cfg = [
            'version' => 1,
            'jobs' => [
                'graph_ingest' => [
                    'label' => 'Graph daemon ingest',
                    'type' => 'graph_ingest',
                    'enabled' => true,
                    'cron' => '*/5 * * * *',
                    'max' => 500,
                ],
                'meeting_billing' => [
                    'label' => 'Meeting billing runner',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '*/1 * * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/meet/cron/meeting-billing.php',
                    'max' => 1,
                ],
                'meeting_ingest' => [
                    'label' => 'Meeting recordings ingest',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '*/2 * * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/meet/cron/meeting-ingest.php',
                    'max' => 1,
                ],
                'meeting_artifacts' => [
                    'label' => 'Meeting transcript + summary pipeline',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '*/3 * * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/meet/cron/meeting-artifacts.php',
                    'max' => 1,
                ],
                'tmp_cleanup' => [
                    'label' => 'Daily /tmp cleanup',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '15 3 * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --tmp-cleanup',
                    'max' => 1,
                    'max_seconds' => 240,
                ],
                'mariadb3307_log_maintenance' => [
                    'label' => 'Daily MariaDB 3307 log maintenance',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '20 3 * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --mariadb3307-maint',
                    'max' => 1,
                    'max_seconds' => 60,
                ],
                'global_ui_sync_daily' => [
                    'label' => 'Daily Global UI sync',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '25 3 * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --global-ui-sync',
                    'max' => 1,
                    'max_seconds' => 300,
                ],
                'enterprise_monitor_alerts_rotate' => [
                    'label' => 'Enterprise monitor alert log rotate',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '0 0 * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --enterprise-monitor-alerts-rotate',
                    'max' => 1,
                    'max_seconds' => 30,
                ],
                'meeting_pending_cleanup' => [
                    'label' => 'Daily stale meeting pending cleanup',
                    'type' => 'php',
                    'enabled' => true,
                    'cron' => '5 0 * * *',
                    'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --meeting-pending-cleanup',
                    'max' => 1,
                    'max_seconds' => 60,
                ],
            ],
        ];
        foreach (mh_sync_backup_jobs() as $jobId => $job) {
            $cfg['jobs'][$jobId] = $job;
        }
        foreach (mh_sync_mysql_dumps_job() as $jobId => $job) {
            $cfg['jobs'][$jobId] = $job;
        }
        return $cfg;
    }
    $decoded = json_decode((string)@file_get_contents($path), true);
    $cfg = is_array($decoded) ? $decoded : ['version' => 1, 'jobs' => []];
    if (!isset($cfg['jobs']) || !is_array($cfg['jobs'])) $cfg['jobs'] = [];
    if (!isset($cfg['jobs']['tmp_cleanup'])) {
        $cfg['jobs']['tmp_cleanup'] = [
            'label' => 'Daily /tmp cleanup',
            'type' => 'php',
            'enabled' => true,
            'cron' => '15 3 * * *',
            'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --tmp-cleanup',
            'max' => 1,
            'max_seconds' => 240,
        ];
    }
    if (!isset($cfg['jobs']['mariadb3307_log_maintenance'])) {
        $cfg['jobs']['mariadb3307_log_maintenance'] = [
            'label' => 'Daily MariaDB 3307 log maintenance',
            'type' => 'php',
            'enabled' => true,
            'cron' => '20 3 * * *',
            'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --mariadb3307-maint',
            'max' => 1,
            'max_seconds' => 60,
        ];
    }
    if (!isset($cfg['jobs']['global_ui_sync_daily'])) {
        $cfg['jobs']['global_ui_sync_daily'] = [
            'label' => 'Daily Global UI sync',
            'type' => 'php',
            'enabled' => true,
            'cron' => '25 3 * * *',
            'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --global-ui-sync',
            'max' => 1,
            'max_seconds' => 300,
        ];
    }
    if (!isset($cfg['jobs']['enterprise_monitor_alerts_rotate'])) {
        $cfg['jobs']['enterprise_monitor_alerts_rotate'] = [
            'label' => 'Enterprise monitor alert log rotate',
            'type' => 'php',
            'enabled' => true,
            'cron' => '0 0 * * *',
            'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --enterprise-monitor-alerts-rotate',
            'max' => 1,
            'max_seconds' => 30,
        ];
    }
    if (!isset($cfg['jobs']['meeting_pending_cleanup'])) {
        $cfg['jobs']['meeting_pending_cleanup'] = [
            'label' => 'Daily stale meeting pending cleanup',
            'type' => 'php',
            'enabled' => true,
            'cron' => '5 0 * * *',
            'cmd' => '/opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --meeting-pending-cleanup',
            'max' => 1,
            'max_seconds' => 60,
        ];
    }

    $backupJobs = mh_sync_backup_jobs();
    foreach ($backupJobs as $jobId => $job) {
        if (!isset($cfg['jobs'][$jobId]) || !is_array($cfg['jobs'][$jobId])) {
            $cfg['jobs'][$jobId] = $job;
            continue;
        }
        $enabled = $cfg['jobs'][$jobId]['enabled'] ?? null;
        $cfg['jobs'][$jobId] = array_merge($cfg['jobs'][$jobId], $job);
        if ($enabled !== null) {
            $cfg['jobs'][$jobId]['enabled'] = $enabled;
        }
    }

    foreach (mh_sync_mysql_dumps_job() as $jobId => $job) {
        if (!isset($cfg['jobs'][$jobId]) || !is_array($cfg['jobs'][$jobId])) {
            $cfg['jobs'][$jobId] = $job;
            continue;
        }
        $enabled = $cfg['jobs'][$jobId]['enabled'] ?? null;
        $cfg['jobs'][$jobId] = array_merge($cfg['jobs'][$jobId], $job);
        if ($enabled !== null) {
            $cfg['jobs'][$jobId]['enabled'] = $enabled;
        }
    }

    return $cfg;
}

function mh_sync_cron_save_cfg(array $cfg): bool {
    $path = mh_sync_cron_cfg_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($tmp)) return false;
    return @file_put_contents($path, $tmp . "\n", LOCK_EX) !== false;
}

function mh_sync_cron_load_state(): array {
    $path = mh_sync_cron_state_path();
    if (!is_file($path)) return ['last_run' => []];
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['last_run' => []];
}

function mh_sync_cron_save_state(array $state): void {
    $path = mh_sync_cron_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($tmp)) return;
    @file_put_contents($path, $tmp . "\n", LOCK_EX);
}

function mh_sync_cron_append_run(array $row): void {
    $dir = dirname(mh_sync_cron_runs_path());
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = json_encode($row, JSON_UNESCAPED_SLASHES);
    if (!is_string($tmp) || $tmp === '') return;
    @file_put_contents(mh_sync_cron_runs_path(), $tmp . "\n", FILE_APPEND | LOCK_EX);
}

function mh_sync_cron_field_match(string $field, int $value, int $min, int $max): bool {
    $field = trim($field);
    if ($field === '*') return true;
    $parts = explode(',', $field);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('#^\\*/(\\d+)$#', $p, $m)) {
            $step = max(1, (int)$m[1]);
            if (($value - $min) % $step === 0) return true;
            continue;
        }
        if (preg_match('#^(\\d+)-(\\d+)$#', $p, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a < $min) $a = $min;
            if ($b > $max) $b = $max;
            if ($value >= $a && $value <= $b) return true;
            continue;
        }
        if (preg_match('#^\\d+$#', $p)) {
            $n = (int)$p;
            if ($n === $value) return true;
            continue;
        }
    }
    return false;
}

function mh_sync_cron_matches(string $expr, int $ts): bool {
    $expr = trim($expr);
    $parts = preg_split('/\\s+/', $expr);
    if (!is_array($parts) || count($parts) !== 5) return false;
    [$minF, $hourF, $domF, $monF, $dowF] = $parts;
    $min = (int)date('i', $ts);
    $hour = (int)date('G', $ts);
    $dom = (int)date('j', $ts);
    $mon = (int)date('n', $ts);
    $dow = (int)date('w', $ts);
    return mh_sync_cron_field_match($minF, $min, 0, 59)
        && mh_sync_cron_field_match($hourF, $hour, 0, 23)
        && mh_sync_cron_field_match($domF, $dom, 1, 31)
        && mh_sync_cron_field_match($monF, $mon, 1, 12)
        && mh_sync_cron_field_match($dowF, $dow, 0, 6);
}

function mh_sync_cron_field_valid(string $field, int $min, int $max): bool {
    $field = trim($field);
    if ($field === '*') return true;
    $parts = explode(',', $field);
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') return false;
        if (preg_match('#^\\*/(\\d+)$#', $p, $m)) {
            $step = (int)$m[1];
            if ($step < 1) return false;
            continue;
        }
        if (preg_match('#^(\\d+)-(\\d+)$#', $p, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            if ($a > $b) return false;
            if ($a < $min || $b > $max) return false;
            continue;
        }
        if (preg_match('#^\\d+$#', $p)) {
            $n = (int)$p;
            if ($n < $min || $n > $max) return false;
            continue;
        }
        return false;
    }
    return true;
}

function mh_sync_cron_is_valid_expr(string $expr): bool {
    $expr = trim($expr);
    $parts = preg_split('/\\s+/', $expr);
    if (!is_array($parts) || count($parts) !== 5) return false;
    [$minF, $hourF, $domF, $monF, $dowF] = $parts;
    return mh_sync_cron_field_valid($minF, 0, 59)
        && mh_sync_cron_field_valid($hourF, 0, 23)
        && mh_sync_cron_field_valid($domF, 1, 31)
        && mh_sync_cron_field_valid($monF, 1, 12)
        && mh_sync_cron_field_valid($dowF, 0, 6);
}

function mh_sync_cron_run_job(string $jobId, array $job): array {
    $started = microtime(true);
    $ok = false;
    $out = null;
    $err = null;
    try {
        $type = isset($job['type']) && is_string($job['type']) ? trim((string)$job['type']) : '';
        if ($type === '') $type = $jobId;
        if ($type === 'graph_ingest') {
            require_once dirname(__DIR__, 2) . '/hub/graph/daemon.php';
            $max = isset($job['max']) ? (int)$job['max'] : 500;
            if (!function_exists('mh_graph_daemon_ingest_all')) {
                $err = 'graph_daemon_missing';
            } else {
                $out = call_user_func('mh_graph_daemon_ingest_all', max(1, $max));
            }
            $ok = is_array($out) && (($out['ok'] ?? null) === true);
        } elseif ($type === 'php') {
            $cmd = isset($job['cmd']) && is_string($job['cmd']) ? trim((string)$job['cmd']) : '';
            if ($cmd === '') {
                $err = 'missing_cmd';
            } else {
                $maxSeconds = isset($job['max_seconds']) ? (int)$job['max_seconds'] : 55;
                $maxSeconds = max(1, min(300, $maxSeconds));
                $tokens = preg_split('/\\s+/', $cmd);
                $tokens = is_array($tokens) ? array_values(array_filter($tokens, fn($t) => is_string($t) && trim($t) !== '')) : [];
                if ($tokens === []) {
                    $err = 'invalid_cmd';
                } else {
                    $phpBin = $tokens[0] ?? '';
                    $script = '';
                    $scriptArgs = [];
                    if (is_string($phpBin) && (str_contains($phpBin, '/php') || $phpBin === 'php')) {
                        $script = isset($tokens[1]) ? (string)$tokens[1] : '';
                        $scriptArgs = array_slice($tokens, 1);
                    } else {
                        $script = $phpBin;
                        $scriptArgs = $tokens;
                    }
                    $script = trim($script);
                    if ($script === '' || !is_file($script)) {
                        $err = 'script_not_found';
                    } elseif (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
                        $err = 'pcntl_unavailable';
                    } else {
                        $pid = pcntl_fork();
                        if ($pid === -1) {
                            $err = 'fork_failed';
                        } elseif ($pid === 0) {
                            if (function_exists('pcntl_async_signals')) {
                                pcntl_async_signals(true);
                            }
                            if (function_exists('pcntl_signal') && function_exists('pcntl_alarm')) {
                                pcntl_signal(SIGALRM, function () {
                                    exit(124);
                                });
                                pcntl_alarm($maxSeconds);
                            }
                            try {
                                $GLOBALS['argv'] = $scriptArgs;
                                $_SERVER['argv'] = $scriptArgs;
                                $_SERVER['argc'] = count($scriptArgs);
                                require $script;
                                exit(0);
                            } catch (Throwable $e) {
                                fwrite(STDERR, $e->getMessage() . "\n");
                                exit(2);
                            }
                        } else {
                            $deadline = microtime(true) + $maxSeconds + 2;
                            $status = 0;
                            $code = null;
                            while (microtime(true) < $deadline) {
                                $r = pcntl_waitpid($pid, $status, WNOHANG);
                                if ($r === -1) {
                                    $err = 'wait_failed';
                                    break;
                                }
                                if ($r > 0) {
                                    if (function_exists('pcntl_wifexited') && pcntl_wifexited($status)) {
                                        $code = pcntl_wexitstatus($status);
                                    } else {
                                        $code = 2;
                                    }
                                    break;
                                }
                                usleep(200000);
                            }
                            if ($code === null) {
                                $err = 'timeout';
                                $code = 124;
                            }
                            $out = ['exit_code' => $code];
                            $ok = ($code === 0) && ($err === null || $err === '');
                            if (!$ok && ($err === null || $err === '')) {
                                $err = 'exit_' . (string)$code;
                            }
                        }
                    }
                }
            }
        } else {
            $err = 'unknown_job';
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        $ok = false;
    }
    return [
        'job' => $jobId,
        'type' => $type,
        'ok' => $ok,
        'ms' => (int)round((microtime(true) - $started) * 1000),
        'error' => $err,
        'result' => $out,
    ];
}

function mh_sync_tenant_cleanup(): array {
    if (!function_exists('cue_autoload')) {
        return ['ok' => false, 'error' => 'cue_autoload_unavailable'];
    }
    try {
        cue_autoload('database');
        $pdo = database_getConnectionById('biometrics');
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'biometrics_connection_failed:' . $e->getMessage()];
    }
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'biometrics_connection_failed'];
    }
    if (!function_exists('mh_load_tenant_map_path') || !function_exists('mh_deprovision_tenant_resources')) {
        return ['ok' => false, 'error' => 'tenant_provisioning_unavailable'];
    }

    $users = [];
    try {
        $rows = $pdo->query("SELECT username FROM users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $u = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u !== '') $users[$u] = true;
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'users_query_failed:' . $e->getMessage()];
    }

    $personas = [];
    $personaNames = [];
    try {
        $rows = $pdo->query("SELECT tenant_id, persona_name FROM personas")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $t = isset($r['tenant_id']) ? trim((string)$r['tenant_id']) : '';
            $p = isset($r['persona_name']) ? trim((string)$r['persona_name']) : '';
            if ($t !== '') $personas[$t] = true;
            if ($p !== '') {
                $personas['persona:' . $p] = true;
                $personaNames[] = $p;
            }
        }
    } catch (Throwable $e) {
        $personas = [];
        $personaNames = [];
    }

    $path = mh_load_tenant_map_path();
    $map = file_exists($path) ? json_decode((string)@file_get_contents($path), true) : null;
    if (!is_array($map)) $map = [];

    $expectedDbNames = [];
    if (function_exists('mh_tenant_normalize_for_mysql_db')) {
        foreach ($users as $uname => $_) {
            $tid = 'user:' . $uname;
            $dbn = mh_tenant_normalize_for_mysql_db($tid);
            if (is_string($dbn) && $dbn !== '') {
                $expectedDbNames[$dbn] = $tid;
            }
        }
        foreach ($personaNames as $p) {
            $tid = 'persona:' . $p;
            $dbn = mh_tenant_normalize_for_mysql_db($tid);
            if (is_string($dbn) && $dbn !== '') {
                $expectedDbNames[$dbn] = $tid;
            }
        }
    }

    $scanned = 0;
    $orphans = [];
    $results = [];
    $activeDbNames = [];
    foreach ($expectedDbNames as $dbn => $tid) {
        $activeDbNames[$dbn] = true;
    }
    foreach ($map as $tenantId => $row) {
        if (!is_string($tenantId) || $tenantId === '') continue;
        $scanned++;
        if (is_array($row)) {
            $dn = isset($row['db_name']) ? trim((string)$row['db_name']) : '';
            if ($dn !== '') $activeDbNames[$dn] = true;
        }
        if (function_exists('mh_tenant_normalize_for_mysql_db')) {
            $dn2 = mh_tenant_normalize_for_mysql_db($tenantId);
            if (is_string($dn2) && $dn2 !== '') $activeDbNames[$dn2] = true;
        }
        $isOrphan = false;
        if (str_starts_with($tenantId, 'user:')) {
            $u = trim((string)substr($tenantId, 5));
            if ($u === '' || empty($users[$u])) $isOrphan = true;
        } elseif (str_starts_with($tenantId, 'persona:')) {
            if (empty($personas[$tenantId])) $isOrphan = true;
        } else {
            $isOrphan = true;
        }
        if ($isOrphan) {
            $orphans[] = $tenantId;
            $results[$tenantId] = mh_deprovision_tenant_resources($tenantId);
        }
    }

    $dbConfigOrphans = [];
    $dbConfigResults = [];
    try {
        $configsPath = function_exists('mh_tenant_db_configs_path') ? mh_tenant_db_configs_path() : (function_exists('getDataPath') ? rtrim((string)getDataPath(), '/') . '/config/db_configs.json' : '/data/config/db_configs.json');
        $cfgs = file_exists($configsPath) ? json_decode((string)@file_get_contents($configsPath), true) : null;
        if (!is_array($cfgs)) $cfgs = [];
        foreach ($cfgs as $cfgId => $cfg) {
            if (!is_string($cfgId) || !is_array($cfg)) continue;
            $ctx = isset($cfg['context']) ? trim((string)$cfg['context']) : '';
            $dbName = isset($cfg['name']) ? trim((string)$cfg['name']) : '';
            if ($dbName === '') continue;
            if ($ctx !== 'tenant' && !str_starts_with($dbName, 'tenant_user_') && !str_starts_with($dbName, 'tenant_persona_')) {
                continue;
            }
            if (isset($expectedDbNames[$dbName])) {
                $activeDbNames[$dbName] = true;
                continue;
            }

            $tenantId = null;
            if (str_starts_with($dbName, 'tenant_user_')) {
                $suffix = trim((string)substr($dbName, strlen('tenant_user_')));
                if ($suffix !== '') {
                    $tenantId = 'user:' . $suffix;
                }
            } elseif (str_starts_with($dbName, 'tenant_persona_')) {
                $suffix = trim((string)substr($dbName, strlen('tenant_persona_')));
                if ($suffix !== '') {
                    $tenantId = 'persona:' . $suffix;
                }
            }
            if ($tenantId !== null) {
                $dbConfigOrphans[] = $dbName;
                $dbConfigResults[$dbName] = mh_deprovision_tenant_resources($tenantId);
            } else {
                $activeDbNames[$dbName] = true;
            }
        }
    } catch (Throwable $e) {
        $dbConfigResults['_error'] = $e->getMessage();
    }

    $mysqlOrphaned = [];
    $mysqlResults = [];
    try {
        $dir = '/mysql';
        if (is_dir($dir)) {
            $items = scandir($dir);
            if (is_array($items)) {
                $adminPdo = null;
                $adminConfigId = function_exists('mh_find_block_provisioner_config_id') ? mh_find_block_provisioner_config_id() : null;
                if (is_string($adminConfigId) && $adminConfigId !== '') {
                    try { $adminPdo = database_getConnectionById($adminConfigId); } catch (Throwable $e) { $adminPdo = null; }
                }
                foreach ($items as $name) {
                    if (!is_string($name) || $name === '' || $name === '.' || $name === '..') continue;
                    if (!preg_match('/^tenant_(user|persona)_[a-zA-Z0-9_]+$/', $name)) continue;
                    if (isset($activeDbNames[$name])) continue;
                    $full = $dir . '/' . $name;
                    if (!is_dir($full)) continue;
                    $mysqlOrphaned[] = $name;
                    $row = ['db_name' => $name, 'mysql_dir' => $full, 'dropped' => false, 'deleted_dir' => false];
                    if ($adminPdo instanceof PDO) {
                        try { $adminPdo->exec("DROP DATABASE IF EXISTS `{$name}`"); $row['dropped'] = true; } catch (Throwable $e) { $row['drop_error'] = $e->getMessage(); }
                    }
                    if (function_exists('mh_tenant_delete_dir_recursive')) {
                        $row['deleted_dir'] = mh_tenant_delete_dir_recursive($full);
                    }
                    $mysqlResults[$name] = $row;
                }
            }
        }
    } catch (Throwable $e) {
        $mysqlResults['_error'] = $e->getMessage();
    }

    return [
        'ok' => true,
        'tenant_contexts_file' => $path,
        'scanned' => $scanned,
        'orphaned' => $orphans,
        'results' => $results,
        'db_config_orphaned' => $dbConfigOrphans,
        'db_config_results' => $dbConfigResults,
        'mysql_orphaned' => $mysqlOrphaned,
        'mysql_results' => $mysqlResults,
    ];
}

function mh_sync_tenant_repair(): array {
    if (!function_exists('cue_autoload')) {
        return ['ok' => false, 'error' => 'cue_autoload_unavailable'];
    }
    if (!function_exists('mh_apply_tenant_context')) {
        return ['ok' => false, 'error' => 'tenant_provisioning_unavailable'];
    }
    try {
        cue_autoload('database');
        $pdo = database_getConnectionById('biometrics');
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'biometrics_connection_failed:' . $e->getMessage()];
    }
    if (!$pdo instanceof PDO) {
        return ['ok' => false, 'error' => 'biometrics_connection_failed'];
    }
    $repaired = [];
    $skipped = 0;
    $errors = [];
    try {
        $rows = $pdo->query("SELECT username, tenant_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $u = isset($r['username']) ? trim((string)$r['username']) : '';
            if ($u === '') { $skipped++; continue; }
            $tid = isset($r['tenant_id']) ? trim((string)$r['tenant_id']) : '';
            if ($tid === '') $tid = 'user:' . $u;
            try {
                mh_apply_tenant_context($tid);
                $repaired[] = $tid;
            } catch (Throwable $e) {
                $errors[] = $tid . ':' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'users_query_failed:' . $e->getMessage()];
    }

    return [
        'ok' => true,
        'repaired' => $repaired,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}

function mh_sync_rm_rf(string $path, array &$stats): void {
    if ($path === '' || $path === '/' || $path === '/tmp' || $path === '/var' || $path === '/home') return;
    if (!file_exists($path) && !is_link($path)) return;
    if (is_link($path) || is_file($path)) {
        $sz = @filesize($path);
        if (@unlink($path)) {
            $stats['deleted'] = ($stats['deleted'] ?? 0) + 1;
            $stats['bytes'] = ($stats['bytes'] ?? 0) + (is_int($sz) ? $sz : 0);
        }
        return;
    }
    if (is_dir($path)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $p = $item->getPathname();
            if ($item->isLink() || $item->isFile()) {
                $sz = $item->getSize();
                if (@unlink($p)) {
                    $stats['deleted'] = ($stats['deleted'] ?? 0) + 1;
                    $stats['bytes'] = ($stats['bytes'] ?? 0) + (is_int($sz) ? $sz : 0);
                }
            } elseif ($item->isDir()) {
                @rmdir($p);
            }
        }
        @rmdir($path);
    }
}

function mh_sync_tmp_cleanup(): array {
    $now = time();
    $stats = ['ok' => true, 'deleted' => 0, 'bytes' => 0, 'paths' => []];
    $tmp = '/tmp';
    $minAge = 2 * 86400;

    $patterns = [
        $tmp . '/meta-humans-theia_*.tar',
        $tmp . '/metahumans-docs.tgz',
        $tmp . '/stirling-app.jar',
        $tmp . '/sql*',
        $tmp . '/webpack*_stats.json',
        $tmp . '/wstats.json',
        $tmp . '/webpack_verbose_user.log',
    ];

    foreach ($patterns as $pat) {
        $hits = glob($pat);
        if (!is_array($hits)) continue;
        foreach ($hits as $p) {
            $p = (string)$p;
            if ($p === '' || !is_file($p)) continue;
            $mt = @filemtime($p);
            if (!is_int($mt) || ($now - $mt) < $minAge) continue;
            $sz = @filesize($p);
            if (@unlink($p)) {
                $stats['deleted']++;
                $stats['bytes'] += is_int($sz) ? $sz : 0;
                $stats['paths'][] = $p;
            }
        }
    }

    foreach ([$tmp . '/go-cache', $tmp . '/node-compile-cache'] as $d) {
        if (!is_dir($d)) continue;
        $mt = @filemtime($d);
        if (!is_int($mt) || ($now - $mt) < $minAge) continue;
        mh_sync_rm_rf($d, $stats);
        $stats['paths'][] = $d;
    }

    $skipNames = [
        'mysql3307.sock' => true,
        'mysql3307.pid' => true,
        'mariadb3307.err' => true,
    ];

    $names = @scandir($tmp);
    if (is_array($names)) {
        foreach ($names as $n) {
            $n = (string)$n;
            if ($n === '' || $n === '.' || $n === '..') continue;
            if (isset($skipNames[$n])) continue;
            if (str_starts_with($n, 'systemd-private-')) continue;
            $p = $tmp . '/' . $n;
            if (!is_file($p)) continue;
            $mt = @filemtime($p);
            if (!is_int($mt) || ($now - $mt) < $minAge) continue;
            $sz = @filesize($p);
            if (!is_int($sz) || $sz < 50 * 1024 * 1024) continue;
            if (@unlink($p)) {
                $stats['deleted']++;
                $stats['bytes'] += $sz;
                $stats['paths'][] = $p;
            }
        }
    }

    return $stats;
}

function mh_sync_mariadb3307_maint(): array {
    $path = '/tmp/mariadb3307.err';
    if (!is_file($path)) return ['ok' => true, 'skipped' => true, 'path' => $path];
    $sz = @filesize($path);
    $sz = is_int($sz) ? $sz : 0;
    $max = 5 * 1024 * 1024;
    $out = ['ok' => true, 'path' => $path, 'size' => $sz, 'truncated' => false];
    if ($sz > $max) {
        $origPerm = @fileperms($path);
        $origPerm = is_int($origPerm) ? ($origPerm & 0777) : null;
        if (!is_writable($path)) {
            @chmod($path, 0666);
        }
        $ok = @file_put_contents($path, '') !== false;
        if ($origPerm !== null) {
            @chmod($path, $origPerm);
        }
        $out['ok'] = $ok;
        $out['truncated'] = $ok;
        $out['size_after'] = $ok ? 0 : $sz;
    }
    return $out;
}

function mh_sync_global_ui_sync(): array {
    if (function_exists('initializeSyncSystem')) {
        @initializeSyncSystem();
    }
    $components = [
        'header' => function_exists('getGlobalUIConfigPath') ? getGlobalUIConfigPath('header', 'config') : (mh_sync_cron_data_dir() . '/global-ui/header/header-config.json'),
        'footer' => function_exists('getGlobalUIConfigPath') ? getGlobalUIConfigPath('footer', 'config') : (mh_sync_cron_data_dir() . '/global-ui/footer/footer-config.json'),
        'navigation' => function_exists('getGlobalUIConfigPath') ? getGlobalUIConfigPath('navigation', 'config') : (mh_sync_cron_data_dir() . '/global-ui/navigation/menu-config.json'),
        'hamburger' => (function_exists('getGlobalUIPath') ? getGlobalUIPath() : (mh_sync_cron_data_dir() . '/global-ui')) . '/hamburger/hamburger-config.json',
        'theme' => (function_exists('getDataPath') ? getDataPath() : mh_sync_cron_data_dir()) . '/theme/config.json',
    ];

    $now = time();
    $iso = date('c', $now);
    $statusPath = function_exists('getSyncStatusPath') ? getSyncStatusPath('status') : (mh_sync_cron_data_dir() . '/sync/sync-status.json');
    $logPath = function_exists('getSyncStatusPath') ? getSyncStatusPath('log') : (mh_sync_cron_data_dir() . '/sync/sync-log.json');

    $existing = [];
    if (is_file($statusPath)) {
        $decoded = json_decode((string)@file_get_contents($statusPath), true);
        if (is_array($decoded)) $existing = $decoded;
    }

    $componentStatus = [];
    $errors = [];
    foreach ($components as $name => $path) {
        $path = (string)$path;
        $row = [
            'status' => 'missing',
            'json_last_modified' => null,
            'database_last_modified' => null,
            'conflicts' => [],
        ];
        if (is_file($path)) {
            $mt = @filemtime($path);
            $row['json_last_modified'] = is_int($mt) ? date('c', $mt) : null;
            $raw = @file_get_contents($path);
            $parsed = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($parsed)) {
                $row['status'] = 'synced';
            } else {
                $row['status'] = 'invalid_json';
                $errors[] = $name . ':invalid_json';
            }
        }
        $componentStatus[$name] = $row;
    }

    $newStatus = [
        'sync_enabled' => true,
        'last_sync' => $iso,
        'sync_direction' => (string)($existing['sync_direction'] ?? 'json_to_database'),
        'components' => $componentStatus,
        'auto_sync' => is_array($existing['auto_sync'] ?? null) ? $existing['auto_sync'] : [
            'enabled' => true,
            'interval' => 86400,
            'conflict_resolution' => 'manual',
        ],
        'version' => (string)($existing['version'] ?? '1.0.0'),
        'database_available' => (bool)($existing['database_available'] ?? true),
    ];

    $dir = dirname($statusPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ok = @file_put_contents($statusPath, json_encode($newStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false;

    $log = [];
    if (is_file($logPath)) {
        $decoded = json_decode((string)@file_get_contents($logPath), true);
        if (is_array($decoded)) $log = $decoded;
    }
    if (!isset($log['entries']) || !is_array($log['entries'])) $log['entries'] = [];
    $log['entries'][] = [
        'timestamp' => date('Y-m-d H:i:s', $now),
        'component' => 'SYSTEM',
        'message' => $ok ? 'Daily Global UI sync completed' : 'Daily Global UI sync failed',
        'details' => [
            'components' => array_keys($components),
            'errors' => $errors,
        ],
    ];
    $logDir = dirname($logPath);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logPath, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

    return ['ok' => $ok, 'status_path' => $statusPath, 'log_path' => $logPath, 'errors' => $errors];
}

function mh_sync_meeting_pending_cleanup(int $maxAgeSeconds = 86400): array
{
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (!function_exists('database_loadConfigurations') || !function_exists('database_getConnectionById')) {
        return ['ok' => false, 'error' => 'database_unavailable'];
    }

    $cfgs = database_loadConfigurations();
    if (!is_array($cfgs)) {
        return ['ok' => false, 'error' => 'database_config_unavailable'];
    }

    $tenantDbIds = [];
    foreach ($cfgs as $id => $cfg) {
        if (!is_string($id) || $id === '' || !is_array($cfg)) {
            continue;
        }
        $ctx = isset($cfg['context']) ? strtolower(trim((string)$cfg['context'])) : '';
        $name = isset($cfg['name']) ? strtolower(trim((string)$cfg['name'])) : '';
        $db = isset($cfg['database']) ? strtolower(trim((string)$cfg['database'])) : '';
        if ($ctx === 'tenant' || strpos($id, 'tenant_') === 0 || strpos($name, 'tenant_') === 0 || strpos($name, 'tenant:') === 0 || strpos($db, 'tenant_') === 0) {
            $tenantDbIds[] = $id;
        }
    }

    sort($tenantDbIds);
    $now = time();
    $cutoffUtc = gmdate('Y-m-d H:i:s', $now - max(3600, $maxAgeSeconds));
    $cleared = 0;
    $dbsTouched = 0;
    $details = [];

    foreach ($tenantDbIds as $dbId) {
        try {
            $db = database_getConnectionById($dbId);
        } catch (Throwable) {
            continue;
        }
        if (!$db instanceof PDO) {
            continue;
        }

        try {
            calendar_ensure_tables($db);
            $stmt = $db->prepare("
                UPDATE mh_meetings
                SET token_charge_status = 'none',
                    token_charge_amount = 0,
                    token_charge_due_utc = NULL,
                    token_charged_at_utc = NULL,
                    token_charge_error = :err
                WHERE token_charge_status = 'pending'
                  AND (
                    (status = 'canceled' AND created_at_utc <= :cutoff)
                    OR (token_charge_due_utc IS NOT NULL AND token_charge_due_utc <= :cutoff)
                    OR (token_charge_due_utc IS NULL AND created_at_utc <= :cutoff)
                  )
            ");
            $stmt->execute([
                ':err' => 'stale pending charge cleared automatically on ' . gmdate('c', $now),
                ':cutoff' => $cutoffUtc,
            ]);
            $count = (int)$stmt->rowCount();
            if ($count > 0) {
                $cleared += $count;
                $dbsTouched++;
                $details[] = ['db' => $dbId, 'cleared' => $count];
            }
        } catch (Throwable $e) {
            $details[] = ['db' => $dbId, 'error' => substr($e->getMessage(), 0, 180)];
        }
    }

    return [
        'ok' => true,
        'cutoff_utc' => $cutoffUtc,
        'dbs_checked' => count($tenantDbIds),
        'dbs_touched' => $dbsTouched,
        'cleared' => $cleared,
        'details' => $details,
    ];
}

if (php_sapi_name() === 'cli') {
    $args = $argv ?? [];
    $args = is_array($args) ? $args : [];
    if (in_array('--tmp-cleanup', $args, true)) {
        $r = mh_sync_tmp_cleanup();
        echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
        exit(($r['ok'] ?? false) ? 0 : 2);
    }
    if (in_array('--mariadb3307-maint', $args, true)) {
        $r = mh_sync_mariadb3307_maint();
        echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
        exit(($r['ok'] ?? false) ? 0 : 2);
    }
    if (in_array('--global-ui-sync', $args, true)) {
        $r = mh_sync_global_ui_sync();
        echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
        exit(($r['ok'] ?? false) ? 0 : 2);
    }
    if (in_array('--meeting-pending-cleanup', $args, true)) {
        $r = mh_sync_meeting_pending_cleanup(86400);
        echo json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
        exit(($r['ok'] ?? false) ? 0 : 2);
    }
    if (in_array('--cron-runner', $args, true)) {
        $cfg = mh_sync_cron_load_cfg();
        if (!is_array($cfg) || !isset($cfg['jobs']) || !is_array($cfg['jobs'])) {
            $cfg = ['version' => 1, 'jobs' => []];
        }
        $state = mh_sync_cron_load_state();
        $lastRun = isset($state['last_run']) && is_array($state['last_run']) ? $state['last_run'] : [];
        $now = time();
        $nowMinute = (int)floor($now / 60);
        $runs = [];
        foreach ($cfg['jobs'] as $jobId => $job) {
            if (!is_string($jobId) || !is_array($job)) continue;
            if (($job['enabled'] ?? null) !== true) continue;
            $expr = is_string($job['cron'] ?? null) ? (string)$job['cron'] : '';
            if ($expr === '' || !mh_sync_cron_matches($expr, $now)) continue;
            $prev = isset($lastRun[$jobId]) ? (int)$lastRun[$jobId] : 0;
            if ($prev === $nowMinute) continue;
            $r = mh_sync_cron_run_job($jobId, $job);
            $runs[] = $r;
            $lastRun[$jobId] = $nowMinute;
        }
        $state['last_run'] = $lastRun;
        mh_sync_cron_save_state($state);
        $row = ['ts' => $now, 'ok' => true, 'runs' => $runs];
        foreach ($runs as $r) {
            if (!is_array($r) || (($r['ok'] ?? null) !== true)) $row['ok'] = false;
        }
        mh_sync_cron_append_run($row);
        echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
        exit($row['ok'] ? 0 : 2);
    }
}

// Enforce page permissions
try {
    enforcePagePermissions();
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

// Get sync status
function getSyncStatus(): array {
    $statusFile = getSyncStatusPath('status');
    if (!file_exists($statusFile)) {
        return [];
    }
    
    $content = file_get_contents($statusFile);
    return json_decode($content, true) ?? [];
}

// Get sync log
function getSyncLog(int $limit = 50): array {
    $logFile = getSyncStatusPath('log');
    if (!file_exists($logFile)) {
        return [];
    }
    
    $content = file_get_contents($logFile);
    $data = json_decode($content, true) ?? ['entries' => []];
    
    // Return last N entries
    $entries = array_slice(array_reverse($data['entries']), 0, $limit);
    return array_reverse($entries);
}

// Load sync configuration
$syncStatus = getSyncStatus();
$syncLog = getSyncLog(20);

$cronCfg = mh_sync_cron_load_cfg();
if (!isset($cronCfg['jobs']) || !is_array($cronCfg['jobs'])) $cronCfg['jobs'] = [];
$cronState = mh_sync_cron_load_state();
$cronLastRun = isset($cronState['last_run']) && is_array($cronState['last_run']) ? $cronState['last_run'] : [];
$cronMsg = '';
$cronErr = '';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$cronCsrf = isset($_SESSION['mh_sync_cron_csrf']) && is_string($_SESSION['mh_sync_cron_csrf']) ? (string)$_SESSION['mh_sync_cron_csrf'] : '';
if ($cronCsrf === '') {
    $cronCsrf = bin2hex(random_bytes(16));
    $_SESSION['mh_sync_cron_csrf'] = $cronCsrf;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['cron_action'])) {
    $posted = isset($_POST['cron_csrf']) ? (string)$_POST['cron_csrf'] : '';
    if ($posted === '' || !hash_equals($cronCsrf, $posted)) {
        $cronErr = 'Invalid session';
    } else {
        $action = (string)$_POST['cron_action'];
        $jobId = isset($_POST['job_id']) ? (string)$_POST['job_id'] : '';
        if ($action === 'toggle' && $jobId !== '' && isset($cronCfg['jobs'][$jobId]) && is_array($cronCfg['jobs'][$jobId])) {
            $enabled = !empty($_POST['enabled']);
            $cronCfg['jobs'][$jobId]['enabled'] = $enabled;
            if (mh_sync_cron_save_cfg($cronCfg)) {
                $cronMsg = 'Saved';
            } else {
                $cronErr = 'Save failed';
            }
        } elseif ($action === 'update' && $jobId !== '' && isset($cronCfg['jobs'][$jobId]) && is_array($cronCfg['jobs'][$jobId])) {
            $expr = isset($_POST['cron_expr']) ? trim((string)$_POST['cron_expr']) : '';
            $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
            $max = isset($_POST['max']) ? (int)$_POST['max'] : null;
            if ($expr === '' || !mh_sync_cron_is_valid_expr($expr)) {
                $cronErr = 'Invalid cron expression';
            } else {
                if ($label !== '') $cronCfg['jobs'][$jobId]['label'] = $label;
                $cronCfg['jobs'][$jobId]['cron'] = $expr;
                if (is_int($max)) $cronCfg['jobs'][$jobId]['max'] = max(1, $max);
                if (mh_sync_cron_save_cfg($cronCfg)) {
                    $cronMsg = 'Updated';
                } else {
                    $cronErr = 'Save failed';
                }
            }
        } elseif ($action === 'delete' && $jobId !== '' && isset($cronCfg['jobs'][$jobId])) {
            unset($cronCfg['jobs'][$jobId]);
            if (mh_sync_cron_save_cfg($cronCfg)) {
                $cronMsg = 'Deleted';
            } else {
                $cronErr = 'Save failed';
            }
        } elseif ($action === 'add') {
            $newId = isset($_POST['new_job_id']) ? trim((string)$_POST['new_job_id']) : '';
            $type = isset($_POST['type']) ? trim((string)$_POST['type']) : '';
            $expr = isset($_POST['cron_expr']) ? trim((string)$_POST['cron_expr']) : '';
            $label = isset($_POST['label']) ? trim((string)$_POST['label']) : '';
            $max = isset($_POST['max']) ? (int)$_POST['max'] : 500;
            if ($newId === '' || preg_match('/^[a-zA-Z0-9_\\-]{2,64}$/', $newId) !== 1) {
                $cronErr = 'Invalid job id';
            } elseif (!in_array($type, ['graph_ingest', 'php'], true)) {
                $cronErr = 'Invalid job type';
            } elseif ($expr === '' || !mh_sync_cron_is_valid_expr($expr)) {
                $cronErr = 'Invalid cron expression';
            } elseif (isset($cronCfg['jobs'][$newId])) {
                $cronErr = 'Job id already exists';
            } else {
                $cronCfg['jobs'][$newId] = [
                    'label' => $label !== '' ? $label : $newId,
                    'type' => $type,
                    'enabled' => true,
                    'cron' => $expr,
                    'max' => max(1, $max),
                ];
                if ($type === 'php') {
                    $cmd = isset($_POST['cmd']) ? trim((string)$_POST['cmd']) : '';
                    if ($cmd !== '') {
                        $cronCfg['jobs'][$newId]['cmd'] = $cmd;
                    }
                    $cronCfg['jobs'][$newId]['max_seconds'] = 55;
                    $cronCfg['jobs'][$newId]['max'] = 1;
                }
                if (mh_sync_cron_save_cfg($cronCfg)) {
                    $cronMsg = 'Added';
                } else {
                    $cronErr = 'Save failed';
                }
            }
        } elseif ($action === 'run_now' && $jobId !== '' && isset($cronCfg['jobs'][$jobId]) && is_array($cronCfg['jobs'][$jobId])) {
            $r = mh_sync_cron_run_job($jobId, (array)$cronCfg['jobs'][$jobId]);
            mh_sync_cron_append_run(['ts' => time(), 'manual' => true, 'run' => $r, 'ok' => (bool)($r['ok'] ?? false)]);
            if (($r['ok'] ?? null) === true) {
                $cronMsg = 'Ran: ' . $jobId;
            } else {
                $cronErr = 'Run failed: ' . (string)($r['error'] ?? 'error');
            }
        } else {
            $cronErr = 'Unknown action';
        }
        $cronCfg = mh_sync_cron_load_cfg();
        if (!isset($cronCfg['jobs']) || !is_array($cronCfg['jobs'])) $cronCfg['jobs'] = [];
        $cronState = mh_sync_cron_load_state();
        $cronLastRun = isset($cronState['last_run']) && is_array($cronState['last_run']) ? $cronState['last_run'] : [];
    }
}

$tenantCsrf = isset($_SESSION['mh_sync_tenant_csrf']) && is_string($_SESSION['mh_sync_tenant_csrf']) ? (string)$_SESSION['mh_sync_tenant_csrf'] : '';
if ($tenantCsrf === '') {
    $tenantCsrf = bin2hex(random_bytes(16));
    $_SESSION['mh_sync_tenant_csrf'] = $tenantCsrf;
}
$tenantMsg = '';
$tenantErr = '';
$tenantReport = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['tenant_action'])) {
    $posted = isset($_POST['tenant_csrf']) ? (string)$_POST['tenant_csrf'] : '';
    if ($posted === '' || !hash_equals($tenantCsrf, $posted)) {
        $tenantErr = 'Invalid session';
    } else {
        $act = (string)$_POST['tenant_action'];
        if ($act === 'sync_cleanup') {
            $tenantReport = mh_sync_tenant_cleanup();
            if (is_array($tenantReport) && (($tenantReport['ok'] ?? null) === true)) {
                $tenantMsg = 'Tenant cleanup completed';
            } else {
                $tenantErr = 'Tenant cleanup failed';
            }
        } elseif ($act === 'sync_repair') {
            $tenantReport = mh_sync_tenant_repair();
            if (is_array($tenantReport) && (($tenantReport['ok'] ?? null) === true)) {
                $tenantMsg = 'Tenant repair completed';
            } else {
                $tenantErr = 'Tenant repair failed';
            }
        } else {
            $tenantErr = 'Unknown action';
        }
    }
}

// Page metadata
$pageTitle = 'Global UI Sync Manager';
$pageDescription = 'Manage synchronization between JSON configuration files and database';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    
    <!-- CUE Framework Widgets -->
    <?php
    try {
        includeLoaderWidget();
        includeNoticesWidget();
    } catch (Exception $e) {
        error_log('Failed to include sync manager widgets: ' . $e->getMessage());
    }
    ?>
    
    <!-- Sync Manager Styles -->
    <link rel="stylesheet" href="assets/sync-styles.css">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/templates/global-ui/includes/complete-head.php'; ?>
    
    <style>
        :root {
            --primary-color: #00d4ff;
            --secondary-color: #7c3aed;
            --dark-bg: #0a0a0a;
            --surface-bg: #1a1612;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
            --border-color: #1f1f1f;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --error-color: #ef4444;
        }

        body {
            font-family: 'Rajdhani', sans-serif;
            background: var(--theme-background, #1a1a1a);
            color: var(--theme-text, #00ffff);
            min-height: 100vh;
            padding: 0;
        }

        main.main-content {
            padding: 20px;
        }

        .sync-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .sync-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .sync-header h1 {
            font-family: 'Orbitron', monospace;
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            text-shadow: 0 0 20px rgba(0, 212, 255, 0.5);
        }

        .sync-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .sync-panel {
            background: linear-gradient(135deg, rgba(26, 22, 18, 0.9) 0%, rgba(45, 40, 33, 0.9) 100%);
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: 12px;
            padding: 2rem;
            backdrop-filter: blur(10px);
        }

        .panel-title {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-grid {
            display: grid;
            gap: 1rem;
        }

        .component-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            border-left: 4px solid var(--border-color);
        }

        .component-status.synced {
            border-left-color: var(--success-color);
        }

        .component-status.pending {
            border-left-color: var(--warning-color);
        }

        .component-status.conflict {
            border-left-color: var(--error-color);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-badge.synced {
            background: rgba(16, 185, 129, 0.2);
            color: var(--success-color);
        }

        .status-badge.pending {
            background: rgba(245, 158, 11, 0.2);
            color: var(--warning-color);
        }

        .status-badge.conflict {
            background: rgba(239, 68, 68, 0.2);
            color: var(--error-color);
        }

        .sync-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            flex-wrap: wrap;
        }
        
        .settings-content {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .setting-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .setting-group label {
            font-weight: 600;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .setting-group select {
            padding: 0.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 0.375rem;
            background: #1e3a8a;
            color: var(--text-color);
            font-size: 0.9rem;
        }
        
        .setting-group input[type="checkbox"] {
            margin-right: 0.5rem;
        }
        
        .setting-description {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            color: #000;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text-primary);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 212, 255, 0.4);
        }

        .log-entries {
            max-height: 400px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-timestamp {
            color: var(--text-secondary);
            margin-right: 1rem;
        }

        .log-component {
            color: var(--primary-color);
            font-weight: bold;
            margin-right: 0.5rem;
        }

        @media (max-width: 768px) {
            .sync-grid {
                grid-template-columns: 1fr;
            }
            
            .sync-actions {
                justify-content: center;
            }
        }
    </style>
</head>
<body class="gear-sync">
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/templates/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
    <div class="sync-container">
        <div class="sync-header">
            <h1>🔄 Global UI Sync Manager</h1>
            <p>Manage synchronization between JSON configuration files and database storage</p>
        </div>

        <div class="sync-panel" style="margin-bottom: 20px;">
            <h2 class="panel-title">
                <i class="fas fa-database"></i>
                Tenant Storage Sync
            </h2>
            <?php if ($tenantMsg !== ''): ?>
                <div class="sync-alert success"><?php echo htmlspecialchars($tenantMsg); ?></div>
            <?php endif; ?>
            <?php if ($tenantErr !== ''): ?>
                <div class="sync-alert error"><?php echo htmlspecialchars($tenantErr); ?></div>
            <?php endif; ?>
            <form method="POST" class="sync-actions" style="justify-content:flex-start;">
                <input type="hidden" name="tenant_csrf" value="<?php echo htmlspecialchars($tenantCsrf, ENT_QUOTES); ?>">
                <button class="btn btn-primary" type="submit" name="tenant_action" value="sync_cleanup">
                    <i class="fas fa-trash"></i>
                    Sync + Cleanup Orphan Tenants
                </button>
                <button class="btn btn-primary" type="submit" name="tenant_action" value="sync_repair">
                    <i class="fas fa-wrench"></i>
                    Repair Tenant DBs
                </button>
            </form>
            <?php if (is_array($tenantReport)): ?>
                <pre style="margin-top: 12px; white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px;"><?php echo htmlspecialchars(json_encode($tenantReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            <?php endif; ?>
        </div>

        <div class="sync-grid">
            <!-- Sync Status Panel -->
            <div class="sync-panel">
                <h2 class="panel-title">
                    <i class="fas fa-chart-line"></i>
                    Sync Status
                </h2>
                
                <div class="status-grid">
                    <?php foreach (['header', 'footer', 'navigation', 'theme', 'hamburger'] as $component): ?>
                        <?php 
                        $componentStatus = $syncStatus['components'][$component] ?? ['status' => 'pending'];
                        $statusClass = $componentStatus['status'];
                        ?>
                        <div class="component-status <?php echo $statusClass; ?>">
                            <div>
                                <strong><?php echo ucfirst($component); ?></strong>
                                <div class="text-secondary">
                                    Last sync: <?php echo $syncStatus['last_sync'] ?? 'Never'; ?>
                                </div>
                            </div>
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <?php echo $statusClass; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="sync-actions">
                    <button class="btn btn-primary" onclick="forceSyncAll()">
                        <i class="fas fa-sync"></i>
                        Force Sync All
                    </button>
                    <button class="btn btn-secondary" onclick="refreshStatus()">
                        <i class="fas fa-refresh"></i>
                        Refresh Status
                    </button>
                </div>
            </div>


            
            <!-- Settings Panel -->
            <div class="sync-panel">
                <h2 class="panel-title">
                    <i class="fas fa-cog"></i>
                    Settings
                </h2>
                
                <div class="settings-content">
                    <div class="setting-group">
                        <label for="refreshInterval">Auto Refresh Interval:</label>
                        <select id="refreshInterval" onchange="updateRefreshInterval()">
                            <option value="0">Disabled</option>
                            <option value="5">5 seconds</option>
                            <option value="10">10 seconds</option>
                            <option value="30">30 seconds</option>
                            <option value="60">1 minute</option>
                            <option value="300">5 minutes</option>
                            <option value="600">10 minutes</option>
                            <option value="900">15 minutes</option>
                            <option value="1800">30 minutes</option>
                            <option value="2700">45 minutes</option>
                            <option value="3600" selected>60 minutes</option>
                        </select>
                        <small class="setting-description">How often to automatically refresh sync status</small>
                    </div>
                    
                    <div class="setting-group">
                        <label>
                            <input type="checkbox" id="enableNotifications" onchange="updateNotificationSettings()" checked>
                            Enable Browser Notifications
                        </label>
                        <small class="setting-description">Show desktop notifications for sync operations</small>
                    </div>
                    
                    <div class="setting-group">
                        <button class="btn btn-secondary" onclick="resetSettings()">
                            <i class="fas fa-undo"></i>
                            Reset to Defaults
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sync Log Panel -->
        <div class="sync-panel">
            <h2 class="panel-title">
                <i class="fas fa-list"></i>
                Sync Log
            </h2>
            
            <div class="log-entries">
                <?php if (empty($syncLog)): ?>
                    <div class="log-entry">
                        <span class="text-secondary">No sync operations yet</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($syncLog as $entry): ?>
                        <div class="log-entry">
                            <span class="log-timestamp"><?php echo $entry['timestamp'] ?? 'Unknown'; ?></span>
                            <span class="log-component"><?php echo strtoupper($entry['component'] ?? 'SYSTEM'); ?></span>
                            <?php echo htmlspecialchars($entry['message'] ?? 'No message'); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="sync-actions">
                <button class="btn btn-warning" onclick="clearSyncLog()">
                    <i class="fas fa-trash-alt"></i>
                    Clear Sync Log
                </button>
                <button class="btn btn-secondary" onclick="refreshLog()">
                    <i class="fas fa-refresh"></i>
                    Refresh Log
                </button>
            </div>
        </div>

        <div class="sync-panel">
            <h2 class="panel-title">
                <i class="fas fa-clock"></i>
                Cron Block
            </h2>
            <?php if ($cronMsg !== ''): ?>
                <div class="log-entry"><span class="log-component">CRON</span><?php echo htmlspecialchars($cronMsg); ?></div>
            <?php endif; ?>
            <?php if ($cronErr !== ''): ?>
                <div class="log-entry"><span class="log-component">CRON</span><?php echo htmlspecialchars($cronErr); ?></div>
            <?php endif; ?>
            <div class="log-entries">
                <?php if (empty($cronCfg['jobs'])): ?>
                    <div class="log-entry"><span class="text-secondary">No cron jobs configured</span></div>
                <?php else: ?>
                    <?php foreach ($cronCfg['jobs'] as $jobId => $job): ?>
                        <?php
                            $label = is_array($job) && isset($job['label']) ? (string)$job['label'] : (string)$jobId;
                            $enabled = is_array($job) && (($job['enabled'] ?? null) === true);
                            $expr = is_array($job) && isset($job['cron']) ? (string)$job['cron'] : '';
                            $type = is_array($job) && isset($job['type']) ? (string)$job['type'] : (string)$jobId;
                            $max = is_array($job) && isset($job['max']) ? (int)$job['max'] : 500;
                            $lastMinute = isset($cronLastRun[$jobId]) ? (int)$cronLastRun[$jobId] : 0;
                            $lastTs = $lastMinute > 0 ? ($lastMinute * 60) : 0;
                        ?>
                        <div class="log-entry">
                            <div style="display:flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center;">
                                <div>
                                    <span class="log-component"><?php echo htmlspecialchars((string)$jobId); ?></span>
                                    <?php echo htmlspecialchars($label); ?> <span class="text-secondary">(<?php echo htmlspecialchars($type); ?>)</span>
                                    <div class="text-secondary" style="margin-top:6px;">
                                        cron: <?php echo htmlspecialchars($expr); ?>
                                        · last run: <?php echo $lastTs > 0 ? htmlspecialchars(gmdate('Y-m-d H:i:s', $lastTs) . ' UTC') : 'never'; ?>
                                    </div>
                                    <div style="margin-top:10px; display:flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                                        <form method="post" style="display:flex; gap:10px; flex-wrap: wrap; align-items:center;">
                                            <input type="hidden" name="cron_csrf" value="<?php echo htmlspecialchars($cronCsrf); ?>" />
                                            <input type="hidden" name="cron_action" value="update" />
                                            <input type="hidden" name="job_id" value="<?php echo htmlspecialchars((string)$jobId); ?>" />
                                            <input class="form-control" type="text" name="label" value="<?php echo htmlspecialchars($label); ?>" style="min-width:200px;" />
                                            <input class="form-control" type="text" name="cron_expr" value="<?php echo htmlspecialchars($expr); ?>" style="min-width:160px; font-family:'Courier New',monospace;" />
                                            <?php if ($type === 'graph_ingest'): ?>
                                                <input class="form-control" type="number" name="max" value="<?php echo (int)$max; ?>" min="1" max="5000" style="width:120px;" />
                                            <?php endif; ?>
                                            <button class="btn btn-secondary" type="submit">Update</button>
                                        </form>
                                    </div>
                                </div>
                                <div style="display:flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="cron_csrf" value="<?php echo htmlspecialchars($cronCsrf); ?>" />
                                        <input type="hidden" name="cron_action" value="toggle" />
                                        <input type="hidden" name="job_id" value="<?php echo htmlspecialchars((string)$jobId); ?>" />
                                        <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>" />
                                        <button class="btn btn-secondary" type="submit"><?php echo $enabled ? 'Disable' : 'Enable'; ?></button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="cron_csrf" value="<?php echo htmlspecialchars($cronCsrf); ?>" />
                                        <input type="hidden" name="cron_action" value="run_now" />
                                        <input type="hidden" name="job_id" value="<?php echo htmlspecialchars((string)$jobId); ?>" />
                                        <button class="btn btn-secondary" type="submit">Run Now</button>
                                    </form>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="cron_csrf" value="<?php echo htmlspecialchars($cronCsrf); ?>" />
                                        <input type="hidden" name="cron_action" value="delete" />
                                        <input type="hidden" name="job_id" value="<?php echo htmlspecialchars((string)$jobId); ?>" />
                                        <button class="btn btn-warning" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="sync-actions">
                <form method="post" style="display:flex; gap: 10px; flex-wrap: wrap; align-items:center; width:100%;">
                    <input type="hidden" name="cron_csrf" value="<?php echo htmlspecialchars($cronCsrf); ?>" />
                    <input type="hidden" name="cron_action" value="add" />
                    <select class="form-control" name="type" style="width:160px;">
                        <option value="graph_ingest">graph_ingest</option>
                        <option value="php">php</option>
                    </select>
                    <input class="form-control" type="text" name="new_job_id" placeholder="job_id" style="min-width:160px; font-family:'Courier New',monospace;" />
                    <input class="form-control" type="text" name="label" placeholder="label" style="min-width:200px;" />
                    <input class="form-control" type="text" name="cron_expr" value="* * * * *" style="min-width:160px; font-family:'Courier New',monospace;" />
                    <input class="form-control" type="text" name="cmd" placeholder="cmd (php only)" style="min-width:260px; font-family:'Courier New',monospace;" />
                    <input class="form-control" type="number" name="max" value="500" min="1" max="5000" style="width:120px;" />
                    <button class="btn btn-secondary" type="submit">Add Job</button>
                </form>
                <div class="text-secondary" style="font-family: 'Courier New', monospace;">
                    Install (root) cron runner: <span style="opacity:0.9;">*/1 * * * * root /opt/cpanel/ea-php83/root/usr/bin/php /home/onemeta/public_html/gear/sync/index.php --cron-runner</span>
                </div>
            </div>
        </div>

        <!-- Component Management Grid -->
        <div class="sync-panel">
            <h2 class="panel-title">
                <i class="fas fa-cogs"></i>
                Component Management
            </h2>
            
            <div class="sync-grid">
                <?php foreach (['header', 'footer', 'navigation', 'theme', 'hamburger'] as $component): ?>
                    <div class="component-panel">
                        <h3><?php echo ucfirst($component); ?> Configuration</h3>
                        <div class="component-actions">
                            <button class="btn btn-secondary" onclick="syncComponent('<?php echo $component; ?>')">
                                <i class="fas fa-sync"></i>
                                Sync <?php echo ucfirst($component); ?>
                            </button>
                            <button class="btn btn-secondary" onclick="editComponent('<?php echo $component; ?>')">
                                <i class="fas fa-edit"></i>
                                Edit
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Include sync manager JavaScript -->
    <script src="assets/sync-manager.js?v=2.0.0&t=<?php echo time(); ?>"></script>
    
    <script>
        // Initialize sync manager


        // Settings management
        let syncManagerInstance = null;
        let currentSettings = {
            refreshInterval: 3600,
            enableNotifications: true
        };
        
        function loadSettings() {
            const saved = localStorage.getItem('syncManagerSettings');
            if (saved) {
                currentSettings = { ...currentSettings, ...JSON.parse(saved) };
            }
            
            // Apply settings to UI
            document.getElementById('refreshInterval').value = currentSettings.refreshInterval;
            document.getElementById('enableNotifications').checked = currentSettings.enableNotifications;
        }
        
        function saveSettings() {
            localStorage.setItem('syncManagerSettings', JSON.stringify(currentSettings));
            console.log('Settings saved immediately:', new Date().toLocaleTimeString());
            
            // Show save notification using notices widget
            if (window.popupNotice) {
                window.popupNotice.show('Settings saved successfully', 'success');
            }
        }
        
        function updateRefreshInterval() {
            const interval = parseInt(document.getElementById('refreshInterval').value);
            currentSettings.refreshInterval = interval;
            saveSettings(); // Immediate save on change
            
            if (syncManagerInstance) {
                syncManagerInstance.updateRefreshInterval(interval);
            }
            
            // Convert to human-readable format for logging
            const minutes = Math.floor(interval / 60);
            const seconds = interval % 60;
            let timeText = '';
            if (minutes > 0) timeText += `${minutes} minute${minutes > 1 ? 's' : ''}`;
            if (seconds > 0) timeText += (minutes > 0 ? ' ' : '') + `${seconds} second${seconds > 1 ? 's' : ''}`;
            if (interval === 0) timeText = 'disabled';
            
            console.log(`Refresh interval updated to ${timeText} (${interval}s) and saved immediately`);
            
            // Show specific notice for refresh interval change
            if (window.popupNotice) {
                window.popupNotice.show(`Refresh interval set to ${timeText}`, 'info');
            }
        }
        
        function updateNotificationSettings() {
            const enabled = document.getElementById('enableNotifications').checked;
            currentSettings.enableNotifications = enabled;
            saveSettings(); // Immediate save on change
            
            if (enabled && 'Notification' in window && Notification.permission !== 'granted') {
                Notification.requestPermission();
            }
            
            console.log(`Notifications ${enabled ? 'enabled' : 'disabled'} and saved immediately`);
            
            // Show specific notice for notification setting change
            if (window.popupNotice) {
                window.popupNotice.show(`Notifications ${enabled ? 'enabled' : 'disabled'}`, 'info');
            }
        }
        
        function resetSettings() {
            currentSettings = {
                refreshInterval: 3600,
                enableNotifications: true
            };
            saveSettings();
            loadSettings();
            
            if (syncManagerInstance) {
                syncManagerInstance.updateRefreshInterval(3600);
            }
            
            console.log('Settings reset to defaults (60 minutes refresh)');
            
            // Show notice for settings reset
            if (window.popupNotice) {
                window.popupNotice.show('Settings reset to defaults', 'warning');
            }
        }
        
        function showNotification(title, body, isError = false) {
            if (!currentSettings.enableNotifications) return;
            
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(title, {
                    body: body,
                    icon: isError ? '/templates/assets/icons/sync-error.png' : '/templates/assets/icons/sync-success.png'
                });
            }
        }

        // Sync management functions
        async function forceSyncAll() {
            showLoadingAnimation('Synchronizing all components...');
            
            try {
                const response = await fetch('api/clean-sync.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ action: 'sync_all' })
                });

                const result = await response.json();
                
                if (result.success) {
                    console.log('✅ Sync completed successfully');
                    showNotification('Sync Manager', 'Sync completed successfully');
                    refreshStatus();
                } else {
                    throw new Error(result.error || 'Sync failed');
                }
            } catch (error) {
                console.error('❌ Sync failed:', error.message);
                showNotification('Sync Manager', 'Sync failed: ' + error.message, true);
            } finally {
                hideLoadingAnimation();
            }
        }

        async function syncComponent(component) {
            showLoadingAnimation(`Synchronizing ${component}...`);
            
            try {
                const response = await fetch('api/clean-sync.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ action: 'sync_component', component: component })
                });

                const result = await response.json();
                
                if (result.success) {
                    console.log(`✅ ${component} synchronized successfully`);
                    showNotification('Sync Manager', `${component} synchronized successfully`);
                    refreshStatus();
                } else {
                    throw new Error(result.error || 'Component sync failed');
                }
            } catch (error) {
                console.error(`❌ ${component} sync failed:`, error.message);
                showNotification('Sync Manager', `${component} sync failed: ` + error.message, true);
            } finally {
                hideLoadingAnimation();
            }
        }

        async function refreshStatus() {
            try {
                const response = await fetch('api/clean-sync-status.php');
                const result = await response.json();
                
                if (result.success) {
                    // Update UI with new status
                    location.reload(); // Simple refresh for now
                }
            } catch (error) {
                console.error('Failed to refresh status:', error);
            }
        }

        function editComponent(component) {
            // Navigate to component editor (to be implemented)
            window.open(`/gear/settings/dbmanager.php#${component}`, '_blank');
        }

        async function clearSyncLog() {
            if (!confirm('Are you sure you want to clear all sync log entries? This action cannot be undone.')) {
                return;
            }

            try {
                showLoadingAnimation('Clearing sync log...');
                
                const response = await fetch('api/clear-sync-log.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ action: 'clear_log' })
                });

                const result = await response.json();
                
                if (result.success) {
                    showNotification('Sync Manager', 'Sync log cleared successfully', false);
                    refreshLog();
                } else {
                    throw new Error(result.error || 'Failed to clear sync log');
                }
            } catch (error) {
                console.error('❌ Clear sync log failed:', error.message);
                showNotification('Sync Manager', 'Failed to clear sync log: ' + error.message, true);
            } finally {
                hideLoadingAnimation();
            }
        }

        async function refreshLog() {
            try {
                showLoadingAnimation('Refreshing sync log...');
                
                // Simple page reload to refresh log entries
                location.reload();
            } catch (error) {
                console.error('Failed to refresh log:', error);
                showNotification('Sync Manager', 'Failed to refresh log: ' + error.message, true);
                hideLoadingAnimation();
            }
        }
        
        // Initialize settings and sync manager when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
            
            // Request notification permission if enabled
            if (currentSettings.enableNotifications && 'Notification' in window) {
                if (Notification.permission === 'default') {
                    Notification.requestPermission();
                }
            }
            
            // Initialize sync manager if the class is available
            if (typeof SyncManager !== 'undefined') {
                syncManagerInstance = new SyncManager();
                syncManagerInstance.updateRefreshInterval(currentSettings.refreshInterval);
            }
            
            console.log('Sync Manager initialized with immediate save on settings changes');
        });
    </script>
    </main>
    <?php include_once $_SERVER['DOCUMENT_ROOT'] . '/templates/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
