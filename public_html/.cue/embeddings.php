<?php
function embeddings_config(): array {
    static $cfg = null;
    if (is_array($cfg)) return $cfg;
    $cfg = [
        'provider' => 'superhumans',
        'endpoint_path' => '/api/embeddings',
        'timeout_seconds' => 20,
        'vector_size' => 1024,
        'mock' => false,
        'fallback_to_mock' => false,
    ];
    $file = '/data/config/embeddings.json';
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $cfg = array_merge($cfg, $decoded);
        }
    }
    $cfg['endpoint_path'] = is_string($cfg['endpoint_path'] ?? null) ? trim((string)$cfg['endpoint_path']) : '/api/embeddings';
    if ($cfg['endpoint_path'] === '') $cfg['endpoint_path'] = '/api/embeddings';
    $cfg['timeout_seconds'] = max(1, (int)($cfg['timeout_seconds'] ?? 20));
    $cfg['vector_size'] = max(1, (int)($cfg['vector_size'] ?? 1024));
    $cfg['mock'] = !empty($cfg['mock']);
    $cfg['fallback_to_mock'] = !empty($cfg['fallback_to_mock']);
    return $cfg;
}

function embeddings_mock_embed(string $text, int $size): array {
    $size = max(1, $size);
    $out = array_fill(0, $size, 0.0);
    $h = hash('sha256', $text, true);
    $bytes = array_values(unpack('C*', $h));
    $i = 0;
    foreach ($bytes as $b) {
        $idx = $i % $size;
        $out[$idx] = (($b / 255.0) * 2.0) - 1.0;
        $i++;
    }
    return $out;
}

function embeddings_extract_vectors_from_response(mixed $body, int $expectedCount): ?array {
    if (!is_array($body)) return null;
    if (isset($body['data']) && is_array($body['data'])) {
        $vectors = [];
        foreach ($body['data'] as $row) {
            if (is_array($row) && isset($row['embedding']) && is_array($row['embedding'])) {
                $vectors[] = $row['embedding'];
            }
        }
        if (count($vectors) === $expectedCount) return $vectors;
    }
    if (isset($body['embeddings']) && is_array($body['embeddings']) && count($body['embeddings']) === $expectedCount) {
        $ok = true;
        foreach ($body['embeddings'] as $v) {
            if (!is_array($v)) { $ok = false; break; }
        }
        if ($ok) return $body['embeddings'];
    }
    if (isset($body['vector']) && is_array($body['vector']) && $expectedCount === 1) {
        return [$body['vector']];
    }
    if (isset($body['embedding']) && is_array($body['embedding']) && $expectedCount === 1) {
        return [$body['embedding']];
    }
    return null;
}

function embeddings_embed_texts(array $texts): array {
    $cfg = embeddings_config();
    $texts = array_values(array_filter(array_map(fn($t) => is_string($t) ? trim($t) : '', $texts), fn($t) => $t !== ''));
    if ($texts === []) {
        throw new Exception('texts_required');
    }
    $expected = count($texts);
    $size = (int)$cfg['vector_size'];

    if (!empty($cfg['mock'])) {
        return array_map(fn($t) => embeddings_mock_embed($t, $size), $texts);
    }

    if ((string)$cfg['provider'] === 'superhumans') {
        $connectorPath = dirname(__DIR__) . '/hub/lib/superhumans_connector.php';
        if (!function_exists('mh_superhumans_request') && is_file($connectorPath)) {
            require_once $connectorPath;
        }
        if (!function_exists('mh_superhumans_request')) {
            if (!empty($cfg['fallback_to_mock'])) {
                return array_map(fn($t) => embeddings_mock_embed($t, $size), $texts);
            }
            throw new Exception('embedding_provider_unavailable');
        }
        $path = (string)$cfg['endpoint_path'];
        $body = [
            'input' => $texts,
            'vector_size' => $size,
        ];
        $res = mh_superhumans_request('POST', $path, $body, [
            'X-MH-Client' => 'metahumans.one',
            'X-MH-Feature' => 'embeddings',
        ]);
        if (empty($res['ok']) || !is_array($res['body'])) {
            if (!empty($cfg['fallback_to_mock'])) {
                return array_map(fn($t) => embeddings_mock_embed($t, $size), $texts);
            }
            $status = (int)($res['status'] ?? 0);
            throw new Exception('embedding_service_failed_' . $status);
        }
        $vectors = embeddings_extract_vectors_from_response($res['body'], $expected);
        if (!is_array($vectors)) {
            if (!empty($cfg['fallback_to_mock'])) {
                return array_map(fn($t) => embeddings_mock_embed($t, $size), $texts);
            }
            throw new Exception('embedding_response_invalid');
        }
        return $vectors;
    }

    throw new Exception('embedding_provider_not_configured');
}

function embeddings_embed_text(string $text): array {
    $vectors = embeddings_embed_texts([$text]);
    return $vectors[0] ?? [];
}
