<?php
declare(strict_types=1);

function memory_sanitize_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function memory_get_context(): array {
    $username = isset($_SESSION['mh_auth_user']) ? (string)$_SESSION['mh_auth_user'] : '';
    $tenantId = isset($_SESSION['mh_tenant_id']) ? (string)$_SESSION['mh_tenant_id'] : '';
    if ($tenantId === '') {
        $tenantId = $username !== '' ? ('user:' . $username) : 'user:unknown';
    }
    $personaId = isset($_SESSION['mh_selected_persona']) ? (string)$_SESSION['mh_selected_persona'] : '';
    if ($personaId === '') {
        $personaId = isset($_SESSION['mh_auth_persona']) ? (string)$_SESSION['mh_auth_persona'] : '';
    }
    if ($personaId === '') {
        $personaId = $username !== '' ? ('MH-' . $username) : 'MH-unknown';
    }
    $metaHumanId = isset($_SESSION['mh_meta_human_id']) ? (string)$_SESSION['mh_meta_human_id'] : '';
    if ($metaHumanId === '') {
        $metaHumanId = 'meta:' . strtolower(memory_sanitize_id($personaId));
        $_SESSION['mh_meta_human_id'] = $metaHumanId;
    }
    $userId = isset($_SESSION['mh_user_internal_id']) ? (string)$_SESSION['mh_user_internal_id'] : '';
    if ($userId === '') $userId = $username !== '' ? $username : 'unknown';
    $deviceId = isset($_SESSION['mh_device_id']) ? (string)$_SESSION['mh_device_id'] : '';
    $sessionId = session_id();
    return [
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'user_id' => $userId,
        'username' => $username,
        'device_id' => $deviceId,
        'session_id' => $sessionId,
    ];
}

function memory_tenant_root(string $tenantId): string {
    $tenantSafe = strtolower(memory_sanitize_id($tenantId));
    return '/data/tenants/' . $tenantSafe;
}

function memory_ensure_dir(string $dir): bool {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0700, true);
}

function memory_runtime_config(): array {
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $cfg = [
        'budget_root' => '/data/memory/budgets',
        'retrieval' => [
            'enabled' => true,
            'max_requests_per_minute' => 60,
            'max_query_chars_per_minute' => 80000,
            'top_k' => 6,
        ],
    ];
    $file = '/data/config/memory-runtime.json';
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $cfg = array_replace_recursive($cfg, $decoded);
        }
    }
    return $cfg;
}

function memory_budget_take(string $name, int $amount, int $limit, int $windowSeconds): bool {
    $cfg = memory_runtime_config();
    $root = is_string($cfg['budget_root'] ?? null) ? (string)$cfg['budget_root'] : '/data/memory/budgets';
    if (!memory_ensure_dir($root)) return false;
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

function memory_budget_key(string $base, ?string $tenantId = null, ?string $scopeId = null): string
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

function memory_budget_take_scoped(string $base, int $amount, int $limit, int $windowSeconds, ?string $tenantId = null, ?string $scopeId = null): bool
{
    return memory_budget_take(memory_budget_key($base, $tenantId, $scopeId), $amount, $limit, $windowSeconds);
}

function memory_write_event(array $ctx, string $kind, string $text, array $extra = []): ?string {
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? (string)$ctx['tenant_id'] : '';
    $personaId = is_string($ctx['persona_id'] ?? null) ? (string)$ctx['persona_id'] : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? (string)$ctx['meta_human_id'] : '';
    if ($tenantId === '' || $personaId === '' || $metaHumanId === '') return null;

    $tenantRoot = memory_tenant_root($tenantId);
    $personaSafe = strtolower(memory_sanitize_id($personaId));
    $inboxDir = $tenantRoot . '/memory/inbox/' . $personaSafe;
    if (!memory_ensure_dir($inboxDir)) return null;

    $id = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(6));
    $event = array_merge([
        'id' => $id,
        'kind' => $kind !== '' ? $kind : 'text',
        'text' => $text,
        'created_at_utc' => gmdate('c'),
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'user_id' => is_string($ctx['user_id'] ?? null) ? (string)$ctx['user_id'] : '',
        'session_id' => is_string($ctx['session_id'] ?? null) ? (string)$ctx['session_id'] : '',
        'device_id' => is_string($ctx['device_id'] ?? null) ? (string)$ctx['device_id'] : '',
        'source' => 'chat',
    ], $extra);

    $path = $inboxDir . '/' . $id . '.json';
    file_put_contents($path, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    @chmod($path, 0600);
    return $path;
}

function memory_extract_last_user_text(array $messages): string {
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        $m = $messages[$i] ?? null;
        if (!is_array($m)) continue;
        if (($m['role'] ?? null) !== 'user') continue;
        $c = $m['content'] ?? null;
        if (is_string($c)) return trim($c);
        if (is_array($c)) {
            $parts = [];
            foreach ($c as $p) {
                if (!is_array($p)) continue;
                if (($p['type'] ?? null) !== 'text') continue;
                $t = $p['text'] ?? null;
                if (is_string($t) && trim($t) !== '') $parts[] = trim($t);
            }
            return trim(implode("\n", $parts));
        }
    }
    return '';
}

function memory_retrieve(array $ctx, string $query, int $topK = 6): array {
    $tenantId = is_string($ctx['tenant_id'] ?? null) ? (string)$ctx['tenant_id'] : '';
    $personaId = is_string($ctx['persona_id'] ?? null) ? (string)$ctx['persona_id'] : '';
    $metaHumanId = is_string($ctx['meta_human_id'] ?? null) ? (string)$ctx['meta_human_id'] : '';
    $query = trim($query);
    if ($tenantId === '' || $query === '') return [];
    if (!function_exists('embeddings_embed_text') || !function_exists('vector_search')) return [];
    $runtime = memory_runtime_config();
    $retr = is_array($runtime['retrieval'] ?? null) ? (array)$runtime['retrieval'] : [];
    if (empty($retr['enabled'])) return [];
    $topK = max(1, min(12, (int)($retr['top_k'] ?? $topK)));
    $reqLimit = max(1, (int)($retr['max_requests_per_minute'] ?? 60));
    $charLimit = max(1, (int)($retr['max_query_chars_per_minute'] ?? 80000));
    $qChars = strlen($query);
    $scopeId = $personaId . '|' . $metaHumanId;
    if (
        !memory_budget_take_scoped('chat_retrieval_requests', 1, $reqLimit, 60, $tenantId, $scopeId) ||
        !memory_budget_take_scoped('chat_retrieval_chars', $qChars, $charLimit, 60, $tenantId, $scopeId)
    ) {
        return [];
    }
    $vec = embeddings_embed_text($query);
    $filters = ['tenant_id' => $tenantId];
    if ($personaId !== '') $filters['persona_id'] = $personaId;
    if ($metaHumanId !== '') $filters['meta_human_id'] = $metaHumanId;
    $res = vector_search($tenantId, $vec, $filters, $topK);
    $hits = $res['result'] ?? [];
    return is_array($hits) ? $hits : [];
}

function memory_build_system_message(array $hits): ?array {
    $lines = [];
    foreach ($hits as $h) {
        if (!is_array($h)) continue;
        $p = $h['payload'] ?? null;
        if (!is_array($p)) continue;
        $t = isset($p['text']) && is_string($p['text']) ? trim((string)$p['text']) : '';
        if ($t === '') continue;
        $lines[] = '- ' . $t;
        if (count($lines) >= 8) break;
    }
    if ($lines === []) return null;
    $content = "Tenant-scoped memory (use only if relevant):\n" . implode("\n", $lines);
    return ['role' => 'system', 'content' => $content];
}

function memory_inject_into_messages(array &$messages, array $hits): void {
    $sys = memory_build_system_message($hits);
    if (!is_array($sys)) return;
    array_unshift($messages, $sys);
}
