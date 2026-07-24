<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);
if (!defined('CUE_CLI_MODE')) define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';

cue_autoload('graph');
cue_autoload('graphrag');

function mh_graph_sanitize_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function mh_graph_tenant_root(string $tenantId): string {
    $tenantSafe = strtolower(mh_graph_sanitize_id($tenantId));
    return '/data/tenants/' . $tenantSafe;
}

function mh_graph_load_state(string $tenantId): array {
    $path = mh_graph_tenant_root($tenantId) . '/graph/state.json';
    if (!is_file($path)) return ['pos' => 0];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['pos' => 0];
}

function mh_graph_save_state(string $tenantId, array $state): void {
    $dir = mh_graph_tenant_root($tenantId) . '/graph';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $path = $dir . '/state.json';
    file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($path, 0600);
}

function mh_graph_ingest_tenant_log(string $tenantDir, int $maxLines, bool $dryRun = false): array {
    $tenantDir = rtrim($tenantDir, '/');
    $tenantSafe = basename($tenantDir);
    $stateTenantId = str_replace('_', ':', $tenantSafe);
    if (!is_string($stateTenantId) || $stateTenantId === '') {
        return ['tenant' => $tenantSafe, 'processed' => 0, 'error' => 'invalid_tenant'];
    }
    $logPath = $tenantDir . '/memory/log.jsonl';
    if (!is_file($logPath)) {
        return ['tenant' => $stateTenantId, 'processed' => 0, 'skipped' => 'no_log'];
    }

    $state = mh_graph_load_state($stateTenantId);
    $pos = (int)($state['pos'] ?? 0);

    $fh = fopen($logPath, 'rb');
    if ($fh === false) {
        return ['tenant' => $stateTenantId, 'processed' => 0, 'error' => 'open_failed'];
    }
    if ($pos > 0) {
        fseek($fh, $pos);
    }

    $processed = 0;
    $lastPos = $pos;

    $previewEntities = [];
    $canonicalTenantId = $stateTenantId;
    while (!feof($fh) && $processed < $maxLines) {
        $linePos = ftell($fh);
        $line = fgets($fh);
        if (!is_string($line)) break;
        $line = trim($line);
        if ($line === '') { $lastPos = ftell($fh); continue; }
        $row = json_decode($line, true);
        if (!is_array($row)) { $lastPos = ftell($fh); continue; }

        $rowTenantId = is_string($row['tenant_id'] ?? null) ? trim((string)$row['tenant_id']) : '';
        if ($rowTenantId !== '') {
            $canonicalTenantId = $rowTenantId;
        }
        $personaId = is_string($row['persona_id'] ?? null) ? (string)$row['persona_id'] : '';
        $metaHumanId = is_string($row['meta_human_id'] ?? null) ? (string)$row['meta_human_id'] : '';
        $text = is_string($row['text'] ?? null) ? (string)$row['text'] : '';
        $kind = is_string($row['kind'] ?? null) ? (string)$row['kind'] : 'event';
        $created = is_string($row['created_at_utc'] ?? null) ? (string)$row['created_at_utc'] : gmdate('c');
        if ($metaHumanId === '' || trim($text) === '') {
            $lastPos = ftell($fh);
            continue;
        }

        $memId = is_string($row['id'] ?? null) ? trim((string)$row['id']) : '';
        if ($memId === '') {
            $memId = substr(hash('sha256', $canonicalTenantId . '|' . $metaHumanId . '|' . $created . '|' . $text), 0, 24);
        }

        $ctx = [
            'tenant_id' => $canonicalTenantId,
            'persona_id' => $personaId,
            'meta_human_id' => $metaHumanId,
            'user_id' => is_string($row['user_id'] ?? null) ? (string)$row['user_id'] : '',
            'session_id' => is_string($row['session_id'] ?? null) ? (string)$row['session_id'] : '',
            'device_id' => is_string($row['device_id'] ?? null) ? (string)$row['device_id'] : '',
            'username' => is_string($row['username'] ?? null) ? (string)$row['username'] : (is_string($row['user_id'] ?? null) ? (string)$row['user_id'] : ''),
        ];

        $meta = [
            'source' => is_string($row['source'] ?? null) ? (string)$row['source'] : '',
            'tags' => is_array($row['tags'] ?? null) ? array_values((array)$row['tags']) : [],
            'filename' => is_string($row['filename'] ?? null) ? (string)$row['filename'] : '',
            'path' => is_string($row['path'] ?? null) ? (string)$row['path'] : '',
        ];

        if (!$dryRun) {
            try {
                graphrag_ingest_text($ctx, $memId, $kind, $text, $created, $meta);
            } catch (Throwable) {
                fseek($fh, $linePos);
                break;
            }
        } else {
            try {
                $entities = graphrag_extract_entities($text, 12);
                foreach ($entities as $en) {
                    $en = is_string($en) ? trim($en) : '';
                    if ($en === '' || isset($previewEntities[strtolower($en)])) continue;
                    $previewEntities[strtolower($en)] = $en;
                    if (count($previewEntities) >= 12) break;
                }
            } catch (Throwable) {
            }
        }

        $processed++;
        $lastPos = ftell($fh);
    }

    fclose($fh);
    if (!$dryRun) {
        mh_graph_save_state($canonicalTenantId, ['pos' => $lastPos, 'updated_at_utc' => gmdate('c')]);
    }
    $preview = array_values($previewEntities);
    return ['tenant' => $canonicalTenantId, 'processed' => $processed, 'pos' => $lastPos, 'dry_run' => $dryRun, 'preview_entities' => array_slice($preview, 0, 12)];
}

function mh_graph_daemon_ingest_all(int $max = 500, bool $dryRun = false): array
{
    $max = max(1, $max);
    $tenantDirs = glob('/data/tenants/*') ?: [];
    sort($tenantDirs);
    $total = 0;
    $results = [];
    foreach ($tenantDirs as $td) {
        if (!is_dir($td)) continue;
        $r = mh_graph_ingest_tenant_log((string)$td, $max, $dryRun);
        $results[] = $r;
        $total += (int)($r['processed'] ?? 0);
    }
    return ['ok' => true, 'command' => 'ingest', 'dry_run' => $dryRun, 'total_processed' => $total, 'tenants' => $results];
}

if (php_sapi_name() === 'cli') {
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $script !== '' ? (realpath($script) ?: $script) : '';
    if ($real !== '' && (realpath(__FILE__) ?: __FILE__) === $real) {
        $cmd = $argv[1] ?? 'ingest';
        $cmd = is_string($cmd) ? strtolower(trim($cmd)) : 'ingest';
        $max = 500;
        $sleep = 30;
        $dryRun = false;
        foreach ($argv as $a) {
            if (is_string($a) && strpos($a, '--max=') === 0) {
                $max = max(1, (int)substr($a, 6));
            }
            if (is_string($a) && strpos($a, '--sleep=') === 0) {
                $sleep = max(1, (int)substr($a, 8));
            }
            if (is_string($a) && ($a === '--dry-run' || $a === '--dry_run' || $a === '--dry')) {
                $dryRun = true;
            }
        }
        if ($cmd === 'run') {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            while (true) {
                $out = mh_graph_daemon_ingest_all($max, $dryRun);
                $out['timestamp_utc'] = gmdate('c');
                echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
                sleep($sleep);
            }
        }
        if ($cmd !== 'ingest') {
            echo json_encode(['ok' => false, 'error' => 'unknown_command', 'command' => $cmd], JSON_UNESCAPED_SLASHES) . "\n";
            exit(2);
        }
        echo json_encode(mh_graph_daemon_ingest_all($max, $dryRun), JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
}
