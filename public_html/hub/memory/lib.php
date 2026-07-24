<?php
declare(strict_types=1);

function mh_memory_sanitize_id(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function mh_memory_tenant_root(string $tenantId): string
{
    $tenantSafe = strtolower(mh_memory_sanitize_id($tenantId));
    return '/data/tenants/' . $tenantSafe;
}

function mh_memory_ensure_dir(string $dir): bool
{
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0700, true);
}

function mh_memory_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $cfg = [
        'memory_inbox_glob' => '/data/tenants/*/memory/inbox/*/*.json',
        'workbench_inbox_glob' => '/data/tenants/*/inbox/*/*.json',
        'processed_dir' => 'processed',
        'dead_dir' => 'dead',
        'max_events_per_run' => 200,
        'batch_size' => 16,
        'embeddings' => [
            'max_requests_per_minute' => 60,
            'max_text_chars_per_minute' => 200000,
        ],
        'consolidation' => [
            'enabled' => true,
            'interval_seconds' => 1800,
            'window_hours' => 24,
            'max_items' => 200,
            'summary_max_chars' => 2400,
            'max_runs_per_hour' => 4,
        ],
        'deep_consolidation' => [
            'enabled' => true,
            'idle_seconds' => 3600,
            'min_seconds_between_runs' => 86400,
            'max_prompt_chars' => 12000,
            'max_response_chars' => 4000,
            'max_requests_per_day' => 10,
            'max_requests_per_day_global' => 10,
            'max_requests_per_day_per_tenant' => 4,
            'max_requests_per_day_per_scope' => 1,
            'base_url' => null,
            'api_key' => null,
            'model' => null,
            'timeout_sec' => 120,
        ],
        'budget_root' => '/data/memory/budgets',
    ];
    $file = '/data/config/memory-daemon.json';
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $cfg = array_replace_recursive($cfg, $decoded);
        }
    }
    $cfg['max_events_per_run'] = max(1, (int)($cfg['max_events_per_run'] ?? 200));
    $cfg['batch_size'] = max(1, (int)($cfg['batch_size'] ?? 16));
    return $cfg;
}

function mh_memory_budget_take(string $name, int $amount, int $limit, int $windowSeconds): bool
{
    $cfg = mh_memory_config();
    $root = is_string($cfg['budget_root'] ?? null) ? (string)$cfg['budget_root'] : '/data/memory/budgets';
    if (!mh_memory_ensure_dir($root)) return false;
    $file = rtrim($root, '/') . '/' . preg_replace('/[^a-z0-9_\\-]+/i', '_', $name) . '.json';
    $now = time();
    $state = ['window_start' => $now, 'used' => 0];
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }
    $windowStart = (int)($state['window_start'] ?? $now);
    $used = (int)($state['used'] ?? 0);
    if ($windowStart <= 0 || ($now - $windowStart) >= $windowSeconds) {
        $windowStart = $now;
        $used = 0;
    }
    if (($used + $amount) > $limit) {
        return false;
    }
    $state = ['window_start' => $windowStart, 'used' => $used + $amount];
    file_put_contents($file, json_encode($state, JSON_UNESCAPED_SLASHES));
    @chmod($file, 0600);
    return true;
}

function mh_memory_budget_release(string $name, int $amount): void
{
    $amount = max(0, $amount);
    if ($amount <= 0) return;
    $cfg = mh_memory_config();
    $root = is_string($cfg['budget_root'] ?? null) ? (string)$cfg['budget_root'] : '/data/memory/budgets';
    $file = rtrim($root, '/') . '/' . preg_replace('/[^a-z0-9_\\-]+/i', '_', $name) . '.json';
    if (!is_file($file)) return;
    $decoded = json_decode((string)file_get_contents($file), true);
    if (!is_array($decoded)) return;
    $used = max(0, (int)($decoded['used'] ?? 0) - $amount);
    $decoded['used'] = $used;
    file_put_contents($file, json_encode($decoded, JSON_UNESCAPED_SLASHES));
    @chmod($file, 0600);
}

function mh_memory_budget_key(string $base, ?string $tenantId = null, ?string $scopeId = null): string
{
    $parts = [$base];
    if (is_string($tenantId) && trim($tenantId) !== '') {
        $parts[] = 't_' . substr(hash('sha256', $tenantId), 0, 12);
    }
    if (is_string($scopeId) && trim($scopeId) !== '') {
        $parts[] = 's_' . substr(hash('sha256', $scopeId), 0, 12);
    }
    return implode('__', $parts);
}

function mh_memory_budget_take_scoped(string $base, int $amount, int $limit, int $windowSeconds, ?string $tenantId = null, ?string $scopeId = null): bool
{
    $key = mh_memory_budget_key($base, $tenantId, $scopeId);
    return mh_memory_budget_take($key, $amount, $limit, $windowSeconds);
}

function mh_memory_budget_release_scoped(string $base, int $amount, ?string $tenantId = null, ?string $scopeId = null): void
{
    $key = mh_memory_budget_key($base, $tenantId, $scopeId);
    mh_memory_budget_release($key, $amount);
}

function mh_memory_read_json(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function mh_memory_move_to_dir(string $filePath, string $dirName): bool
{
    $base = dirname($filePath);
    $destDir = $base . '/' . $dirName;
    if (!mh_memory_ensure_dir($destDir)) return false;
    $dest = $destDir . '/' . basename($filePath);
    return @rename($filePath, $dest);
}

function mh_memory_event_text(array $event): string
{
    $kind = isset($event['kind']) ? strtolower(trim((string)$event['kind'])) : '';
    if ($kind === '') $kind = 'text';
    if ($kind === 'text') {
        return is_string($event['text'] ?? null) ? trim((string)$event['text']) : '';
    }
    $filename = is_string($event['filename'] ?? null) ? trim((string)$event['filename']) : '';
    $label = $filename !== '' ? $filename : (is_string($event['id'] ?? null) ? (string)$event['id'] : 'asset');
    return trim('uploaded_' . $kind . ' ' . $label);
}

function mh_memory_event_payload(array $event): array
{
    $payload = [];
    foreach (['tenant_id', 'persona_id', 'meta_human_id', 'user_id', 'session_id', 'device_id', 'kind'] as $k) {
        if (isset($event[$k]) && is_string($event[$k]) && trim((string)$event[$k]) !== '') {
            $payload[$k] = (string)$event[$k];
        }
    }
    if (!isset($payload['kind'])) {
        $payload['kind'] = 'memory_event';
    }
    if (isset($event['created_at_utc']) && is_string($event['created_at_utc']) && trim((string)$event['created_at_utc']) !== '') {
        $payload['created_at_utc'] = (string)$event['created_at_utc'];
    } else {
        $payload['created_at_utc'] = gmdate('c');
    }
    if (isset($event['source']) && is_string($event['source']) && trim((string)$event['source']) !== '') {
        $payload['source'] = (string)$event['source'];
    }
    if (isset($event['filename']) && is_string($event['filename']) && trim((string)$event['filename']) !== '') {
        $payload['filename'] = (string)$event['filename'];
    }
    if (isset($event['path']) && is_string($event['path']) && trim((string)$event['path']) !== '') {
        $payload['path'] = (string)$event['path'];
    }
    $text = mh_memory_event_text($event);
    if ($text !== '') {
        $payload['text'] = $text;
    }
    return $payload;
}

function mh_memory_list_event_files(): array
{
    $cfg = mh_memory_config();
    $files = [];
    foreach (['memory_inbox_glob', 'workbench_inbox_glob'] as $k) {
        $g = is_string($cfg[$k] ?? null) ? (string)$cfg[$k] : '';
        if ($g === '') continue;
        foreach (glob($g) ?: [] as $p) {
            if (!is_string($p) || !is_file($p)) continue;
            $base = basename($p);
            if (str_contains($base, '_enc') || str_contains($base, '.bak')) continue;
            if (str_contains($p, '/' . ($cfg['processed_dir'] ?? 'processed') . '/')) continue;
            if (str_contains($p, '/' . ($cfg['dead_dir'] ?? 'dead') . '/')) continue;
            $files[] = $p;
        }
    }
    sort($files);
    return $files;
}
