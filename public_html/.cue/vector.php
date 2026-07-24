<?php
function vector_config(): array {
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $defaultBase = 'https://metahumans.one';
    if (!empty($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $defaultBase = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }
    $cfg = [
        'qdrant_url' => $defaultBase . '/hub/memory/api/qdrant.php',
        'shard_count' => 256,
        'vector_size' => 1024,
        'distance' => 'Cosine',
        'encrypt_payload' => true,
        'encrypt_fields' => ['text', 'snippet', 'content'],
        'enforce_gateway' => true,
    ];
    $file = '/data/config/vector-config.json';
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $cfg = array_merge($cfg, $decoded);
        }
    }
    $cfg['shard_count'] = max(1, (int)($cfg['shard_count'] ?? 256));
    $cfg['vector_size'] = max(1, (int)($cfg['vector_size'] ?? 1024));
    $cfg['qdrant_url'] = is_string($cfg['qdrant_url'] ?? null) ? (string)$cfg['qdrant_url'] : ($defaultBase . '/hub/memory/api/qdrant.php');
    $cfg['enforce_gateway'] = !array_key_exists('enforce_gateway', $cfg) || !empty($cfg['enforce_gateway']);

    if (!empty($cfg['enforce_gateway'])) {
        $u = parse_url((string)$cfg['qdrant_url']);
        $port = is_array($u) && isset($u['port']) ? (int)$u['port'] : 0;
        $path = is_array($u) && isset($u['path']) ? (string)$u['path'] : '';
        if ($port === 6333 || $path === '' || str_ends_with($path, '/collections') || stripos($path, '/qdrant') === false) {
            $cfg['qdrant_url'] = $defaultBase . '/hub/memory/api/qdrant.php';
        }
    }
    return $cfg;
}

function vector_getShardCount(): int { return (int)vector_config()['shard_count']; }
function vector_shardForTenant(string $tenantId): int {
    $h = hash('sha256', $tenantId, true);
    $n = unpack('N', substr($h, 0, 4))[1];
    $c = vector_getShardCount();
    return $n % $c;
}
function vector_requireFilters(array $filters): void {
    if (!isset($filters['tenant_id']) || !is_string($filters['tenant_id']) || $filters['tenant_id'] === '') {
        throw new Exception('tenant_id_required');
    }
}
function vector_collectionForTenant(string $tenantId): string {
    $s = vector_shardForTenant($tenantId);
    return 'mh_shard_' . $s;
}

function vector_masterKey(): string {
    $keyPath = '/data/security/app.key';
    if (function_exists('cue_autoload')) {
        $paths = cue_autoload('paths');
        if (is_object($paths) && method_exists($paths, 'getEncryptionKeyPath')) {
            $keyPath = (string)$paths->getEncryptionKeyPath();
        }
    }
    $key = is_file($keyPath) ? trim((string)file_get_contents($keyPath)) : '';
    return $key;
}

function vector_tenantKey(string $tenantId): string {
    $mk = vector_masterKey();
    if ($mk === '') return '';
    if (function_exists('security_deriveTenantEncryptionKey')) {
        return (string)security_deriveTenantEncryptionKey($mk, $tenantId);
    }
    return hash_hmac('sha256', 'tenant:' . $tenantId, $mk);
}

function vector_encryptPayload(string $tenantId, array $payload): array {
    $cfg = vector_config();
    if (empty($cfg['encrypt_payload'])) return $payload;
    $key = vector_tenantKey($tenantId);
    if ($key === '') return $payload;
    if (function_exists('cue_autoload')) {
        cue_autoload('security');
    }
    $fields = $cfg['encrypt_fields'] ?? [];
    if (!is_array($fields)) $fields = [];
    foreach ($fields as $f) {
        if (!is_string($f) || $f === '') continue;
        if (!isset($payload[$f]) || !is_string($payload[$f]) || $payload[$f] === '') continue;
        if (function_exists('security_encryptValue')) {
            $payload[$f] = security_encryptValue($payload[$f], $key);
            $payload[$f . '_enc'] = 'aes-256-cbc';
        }
    }
    return $payload;
}

function vector_decryptPayload(string $tenantId, array $payload): array {
    $cfg = vector_config();
    if (empty($cfg['encrypt_payload'])) return $payload;
    $key = vector_tenantKey($tenantId);
    if ($key === '') return $payload;
    if (function_exists('cue_autoload')) {
        cue_autoload('security');
    }
    $fields = $cfg['encrypt_fields'] ?? [];
    if (!is_array($fields)) $fields = [];
    foreach ($fields as $f) {
        if (!is_string($f) || $f === '') continue;
        if (!isset($payload[$f]) || !is_string($payload[$f]) || $payload[$f] === '') continue;
        if (!isset($payload[$f . '_enc'])) continue;
        if (function_exists('security_decryptValue')) {
            $payload[$f] = security_decryptValue($payload[$f], $key);
        }
    }
    return $payload;
}

function vector_buildQdrantFilter(string $tenantId, array $filters): array {
    vector_requireFilters($filters);
    if ((string)$filters['tenant_id'] !== $tenantId) {
        throw new Exception('tenant_mismatch');
    }
    $must = [
        ['key' => 'tenant_id', 'match' => ['value' => $tenantId]],
    ];
    foreach (['persona_id', 'meta_human_id'] as $k) {
        if (isset($filters[$k]) && is_string($filters[$k]) && $filters[$k] !== '') {
            $must[] = ['key' => $k, 'match' => ['value' => (string)$filters[$k]]];
        }
    }
    return ['must' => $must];
}

function vector_isUuid(string $id): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id);
}

function vector_uuidFromSeed(string $seed): string {
    $bytes = hash('sha256', $seed, true);
    $b = array_values(unpack('C16', substr($bytes, 0, 16)));
    $b[6] = ($b[6] & 0x0f) | 0x40;
    $b[8] = ($b[8] & 0x3f) | 0x80;
    $hex = bin2hex(pack('C*', ...$b));
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

function vector_normalizePointId(string $tenantId, $id, array $payload): string|int {
    if (is_int($id)) return $id;
    if (is_string($id)) {
        $idTrim = trim($id);
        if ($idTrim !== '') {
            if (ctype_digit($idTrim) && strlen($idTrim) <= 18) {
                return (int)$idTrim;
            }
            if (vector_isUuid($idTrim)) {
                return strtolower($idTrim);
            }
            return vector_uuidFromSeed($tenantId . '|' . $idTrim);
        }
    }
    $seed = $tenantId . '|' . json_encode($payload, JSON_UNESCAPED_SLASHES) . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX);
    return vector_uuidFromSeed($seed);
}

function vector_http(string $method, string $path, ?array $payload = null): array {
    $cfg = vector_config();
    $url = rtrim((string)$cfg['qdrant_url'], '/') . $path;
    $opts = [
        'http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 5,
            'content' => $payload ? json_encode($payload) : null
        ]
    ];
    $ctx = stream_context_create($opts);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/^HTTP\\/\\d+\\.\\d+\\s+(\\d+)/', $h, $m)) { $code = (int)$m[1]; break; }
        }
    }
    $data = $raw ? json_decode($raw, true) : null;
    return ['code' => $code, 'data' => $data];
}
function vector_ensureCollection(string $name): bool {
    $r = vector_http('GET', '/collections/' . urlencode($name));
    if ((int)$r['code'] === 200) return true;
    $cfg = vector_config();
    $p = ['name' => $name, 'vectors' => ['size' => (int)$cfg['vector_size'], 'distance' => (string)$cfg['distance']]];
    $c = vector_http('PUT', '/collections/' . urlencode($name), $p);
    return (int)$c['code'] === 200;
}
function vector_upsert(string $tenantId, array $points): bool {
    $col = vector_collectionForTenant($tenantId);
    if (!vector_ensureCollection($col)) return false;
    $payloadPoints = [];
    foreach ($points as $p) {
        $payload = (array)($p['payload'] ?? []);
        $payload['tenant_id'] = $tenantId;
        $payload = vector_encryptPayload($tenantId, $payload);
        $payloadPoints[] = [
            'id' => vector_normalizePointId($tenantId, $p['id'] ?? null, $payload),
            'vector' => $p['vector'] ?? [],
            'payload' => $payload
        ];
    }
    $res = vector_http('PUT', '/collections/' . urlencode($col) . '/points', ['points' => $payloadPoints]);
    return (int)$res['code'] === 200;
}
function vector_search(string $tenantId, array $query, array $filters, int $topK = 10): array {
    vector_requireFilters($filters);
    $col = vector_collectionForTenant($tenantId);
    $filter = vector_buildQdrantFilter($tenantId, $filters);
    $res = vector_http('POST', '/collections/' . urlencode($col) . '/points/search', [
        'vector' => $query,
        'filter' => $filter,
        'limit' => $topK,
        'with_payload' => true,
        'with_vector' => false
    ]);
    $data = (array)($res['data'] ?? []);
    if (isset($data['result']) && is_array($data['result'])) {
        foreach ($data['result'] as $i => $hit) {
            if (is_array($hit) && isset($hit['payload']) && is_array($hit['payload'])) {
                $data['result'][$i]['payload'] = vector_decryptPayload($tenantId, $hit['payload']);
            }
        }
    }
    return $data;
}
