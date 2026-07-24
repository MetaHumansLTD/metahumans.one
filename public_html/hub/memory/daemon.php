<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once __DIR__ . '/lib.php';

if (function_exists('cue_autoload')) {
    cue_autoload('embeddings');
    cue_autoload('vector');
}

function mh_memory_llm_config(): array
{
    $cfg = mh_memory_config();
    $deep = is_array($cfg['deep_consolidation'] ?? null) ? (array)$cfg['deep_consolidation'] : [];
    $defaults = [
        'enabled' => false,
        'idle_seconds' => 3600,
        'min_seconds_between_runs' => 86400,
        'max_prompt_chars' => 12000,
        'max_response_chars' => 4000,
        'max_requests_per_day' => 24,
        'max_requests_per_day_global' => 24,
        'max_requests_per_day_per_tenant' => 10,
        'max_requests_per_day_per_scope' => 1,
        'base_url' => null,
        'api_key' => null,
        'model' => null,
        'timeout_sec' => 120,
    ];
    $deep = array_merge($defaults, $deep);
    if (isset($GLOBALS['__mh_memory_llm_cfg_override']) && is_array($GLOBALS['__mh_memory_llm_cfg_override'])) {
        $deep = array_merge($deep, (array)$GLOBALS['__mh_memory_llm_cfg_override']);
    }

    if (!is_string($deep['base_url']) || trim((string)$deep['base_url']) === '') {
        $fallback = dirname(__DIR__, 2) . '/ai/hermes.json';
        if (is_file($fallback)) {
            $j = json_decode((string)file_get_contents($fallback), true);
            if (is_array($j)) {
                if (isset($j['base_url']) && is_string($j['base_url']) && trim((string)$j['base_url']) !== '') {
                    $deep['base_url'] = (string)$j['base_url'];
                }
                if (isset($j['api_key']) && is_string($j['api_key']) && trim((string)$j['api_key']) !== '') {
                    $deep['api_key'] = (string)$j['api_key'];
                }
                if (isset($j['model']) && is_string($j['model']) && trim((string)$j['model']) !== '') {
                    $deep['model'] = (string)$j['model'];
                }
                if (isset($j['timeout_sec'])) {
                    $deep['timeout_sec'] = (int)$j['timeout_sec'];
                }
            }
        }
    }
    if (!is_string($deep['base_url'])) $deep['base_url'] = null;
    if (!is_string($deep['api_key'])) $deep['api_key'] = null;
    if (!is_string($deep['model'])) $deep['model'] = null;
    return $deep;
}

function mh_memory_llm_chat(array $messages): array
{
    $cfg = mh_memory_llm_config();
    if (empty($cfg['enabled'])) {
        return ['ok' => false, 'error' => 'disabled'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }
    $baseUrl = is_string($cfg['base_url']) ? trim((string)$cfg['base_url']) : '';
    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'missing_base_url'];
    }
    $fallbackBaseUrl = function_exists('mh_internal_endpoint_url') ? (string)mh_internal_endpoint_url('ollama') : '';
    if (!is_string($fallbackBaseUrl) || trim($fallbackBaseUrl) === '') $fallbackBaseUrl = getenv('MH_OLLAMA_BASE_URL');
    if (!is_string($fallbackBaseUrl) || trim($fallbackBaseUrl) === '') $fallbackBaseUrl = getenv('OLLAMA_BASE_URL');
    if (!is_string($fallbackBaseUrl) || trim($fallbackBaseUrl) === '') $fallbackBaseUrl = getenv('OLLAMA_HOST');
    $fallbackBaseUrl = is_string($fallbackBaseUrl) ? trim($fallbackBaseUrl) : '';
    if ($fallbackBaseUrl === '') $fallbackBaseUrl = 'http://meta.superhumans.one:11434';
    $fallbackBaseUrl = rtrim($fallbackBaseUrl, '/');
    $endpoint = rtrim($baseUrl, '/');
    $u = parse_url($endpoint);
    $port = is_array($u) && isset($u['port']) ? (int)$u['port'] : 0;
    $path = is_array($u) && isset($u['path']) ? (string)$u['path'] : '';
    $isOllama = ($port === 11434) && ($path === '' || $path === '/');
    $isProxy = str_ends_with($endpoint, '/ai/chat.php');
    if ($isOllama) {
        $endpoint = $endpoint . '/api/chat';
        $payload = [
            'model' => is_string($cfg['model']) && trim((string)$cfg['model']) !== '' ? (string)$cfg['model'] : 'hermes3:latest',
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => 0.2,
            ],
        ];
    } else {
        if (!$isProxy) {
            $endpoint = $endpoint . '/v1/chat/completions';
        }
        $payload = [
            'model' => is_string($cfg['model']) && trim((string)$cfg['model']) !== '' ? (string)$cfg['model'] : 'hermes-405b',
            'messages' => $messages,
            'temperature' => 0.2,
        ];
    }
    $ch = curl_init($endpoint);
    $headers = ['Content-Type: application/json'];
    $apiKey = is_string($cfg['api_key']) ? trim((string)$cfg['api_key']) : '';
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(10, (int)$cfg['timeout_sec']),
    ]);
    $resBody = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ch = null;
    $tryFallback = static function () use ($messages, $cfg, $isOllama, $baseUrl, $fallbackBaseUrl): ?array {
        if ($isOllama || rtrim($baseUrl, '/') === $fallbackBaseUrl) {
            return null;
        }
        $fallback = $cfg;
        $fallback['base_url'] = $fallbackBaseUrl;
        $fallback['model'] = 'huihui_ai/gemma-4-abliterated:26b';
        $fallback['timeout_sec'] = max(120, (int)($cfg['timeout_sec'] ?? 0));
        try {
            $GLOBALS['__mh_memory_llm_cfg_override'] = $fallback;
            $tmp = mh_memory_llm_chat($messages);
            unset($GLOBALS['__mh_memory_llm_cfg_override']);
            return $tmp + ['fallback' => true];
        } catch (Throwable) {
            unset($GLOBALS['__mh_memory_llm_cfg_override']);
            return null;
        }
    };
    if (!is_string($resBody)) {
        $fallbackResp = $tryFallback();
        if (is_array($fallbackResp)) {
            return $fallbackResp;
        }
        return ['ok' => false, 'error' => $err !== '' ? $err : 'request_failed', 'status' => $code];
    }
    $data = json_decode($resBody, true);
    if ($code < 200 || $code >= 300) {
        $fallbackResp = $tryFallback();
        if (is_array($fallbackResp) && (($fallbackResp['ok'] ?? false) === true)) {
            return $fallbackResp;
        }
        return ['ok' => false, 'error' => 'http_' . $code, 'status' => $code, 'raw' => $resBody, 'json' => is_array($data) ? $data : null];
    }
    $content = '';
    if ($isOllama && is_array($data) && isset($data['message']['content'])) {
        $content = (string)$data['message']['content'];
    } elseif (is_array($data) && isset($data['reply']) && is_string($data['reply'])) {
        $content = (string)$data['reply'];
    } elseif (is_array($data) && isset($data['choices'][0]['message']['content'])) {
        $content = (string)$data['choices'][0]['message']['content'];
    }
    $content = trim($content);
    if ($content === '') {
        $fallbackResp = $tryFallback();
        if (is_array($fallbackResp) && (($fallbackResp['ok'] ?? false) === true)) {
            return $fallbackResp;
        }
        return ['ok' => false, 'error' => 'empty_completion', 'json' => is_array($data) ? $data : null];
    }
    return ['ok' => true, 'content' => $content];
}

function mh_memory_load_candidate_rows(string $logPath, int $maxItems, int $cutoff): array
{
    $maxItems = max(1, $maxItems);
    $recentRows = [];
    $tailRows = [];
    $fh = fopen($logPath, 'rb');
    if ($fh === false) {
        return [];
    }
    while (!feof($fh)) {
        $line = fgets($fh);
        if (!is_string($line)) break;
        $line = trim($line);
        if ($line === '') continue;
        $j = json_decode($line, true);
        if (!is_array($j)) continue;
        $tailRows[] = $j;
        if (count($tailRows) > $maxItems) {
            array_shift($tailRows);
        }
        $ts = isset($j['created_at_utc']) ? strtotime((string)$j['created_at_utc']) : 0;
        if ($ts !== 0 && $ts < $cutoff) continue;
        $recentRows[] = $j;
        if (count($recentRows) > $maxItems) {
            array_shift($recentRows);
        }
    }
    fclose($fh);
    if ($recentRows !== []) {
        return $recentRows;
    }
    return $tailRows;
}

function mh_memory_release_deep_request_budgets(string $tenantId, string $scopeId): void
{
    mh_memory_budget_release('deep_llm_requests_global', 1);
    mh_memory_budget_release_scoped('deep_llm_requests_tenant', 1, $tenantId, null);
    mh_memory_budget_release_scoped('deep_llm_requests_scope', 1, $tenantId, $scopeId);
}

function mh_memory_deep_state_path(string $tenantId): string
{
    return mh_memory_tenant_root($tenantId) . '/memory/deep_state.json';
}

function mh_memory_deep_load_state(string $tenantId): array
{
    $path = mh_memory_deep_state_path($tenantId);
    if (!is_file($path)) return ['scopes' => []];
    $decoded = json_decode((string)@file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['scopes' => []];
}

function mh_memory_deep_save_state(string $tenantId, array $state): void
{
    $path = mh_memory_deep_state_path($tenantId);
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $tmp = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($tmp)) return;
    @file_put_contents($path, $tmp . "\n", LOCK_EX);
    @chmod($path, 0600);
}

function mh_memory_deep_log_path(string $tenantId): string
{
    return mh_memory_tenant_root($tenantId) . '/memory/deep_consolidation.jsonl';
}

function mh_memory_deep_append_log(string $tenantId, array $row): void
{
    $path = mh_memory_deep_log_path($tenantId);
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $tmp = json_encode($row, JSON_UNESCAPED_SLASHES);
    if (!is_string($tmp) || $tmp === '') return;
    @file_put_contents($path, $tmp . "\n", FILE_APPEND | LOCK_EX);
    @chmod($path, 0600);
}

function mh_memory_cli_arg(string $name, $default = null)
{
    global $argv;
    foreach ($argv as $a) {
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen('--' . $name . '='));
        }
    }
    return $default;
}

function mh_memory_cli_has_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function mh_memory_write_log(string $tenantId, array $row): void
{
    $root = mh_memory_tenant_root($tenantId) . '/memory';
    if (!mh_memory_ensure_dir($root)) return;
    $path = $root . '/log.jsonl';
    file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    @chmod($path, 0600);
}

function mh_memory_ingest_once(): array
{
    $cfg = mh_memory_config();
    $maxEvents = (int)$cfg['max_events_per_run'];
    $batchSize = (int)$cfg['batch_size'];
    $files = array_slice(mh_memory_list_event_files(), 0, $maxEvents);

    $eventsByTenant = [];
    foreach ($files as $path) {
        $event = mh_memory_read_json($path);
        if (!is_array($event)) continue;
        if (!isset($event['source'])) $event['source'] = $path;
        if (!isset($event['tenant_id']) || !is_string($event['tenant_id']) || trim((string)$event['tenant_id']) === '') {
            mh_memory_move_to_dir($path, (string)($cfg['dead_dir'] ?? 'dead'));
            continue;
        }
        $tenantId = (string)$event['tenant_id'];
        $text = mh_memory_event_text($event);
        if ($text === '') {
            mh_memory_move_to_dir($path, (string)($cfg['dead_dir'] ?? 'dead'));
            continue;
        }
        $event['_file'] = $path;
        $event['_text'] = $text;
        $eventsByTenant[$tenantId][] = $event;
    }

    $processed = 0;
    $embedded = 0;
    $written = 0;
    $skipped_budget = 0;

    foreach ($eventsByTenant as $tenantId => $events) {
        $idx = 0;
        while ($idx < count($events)) {
            $chunk = array_slice($events, $idx, $batchSize);
            $idx += $batchSize;

            $texts = [];
            $pointSpecs = [];
            $charCount = 0;
            foreach ($chunk as $e) {
                $texts[] = (string)$e['_text'];
                $charCount += strlen((string)$e['_text']);
                $payload = mh_memory_event_payload($e);
                $id = is_string($e['id'] ?? null) ? (string)$e['id'] : basename((string)$e['_file']);
                $pointSpecs[] = ['id' => $id, 'payload' => $payload];
            }

            $embCfg = is_array($cfg['embeddings'] ?? null) ? (array)$cfg['embeddings'] : [];
            $reqLimit = max(1, (int)($embCfg['max_requests_per_minute'] ?? 60));
            $charLimit = max(1, (int)($embCfg['max_text_chars_per_minute'] ?? 200000));
            if (!mh_memory_budget_take('embeddings_requests', 1, $reqLimit, 60) || !mh_memory_budget_take('embeddings_chars', $charCount, $charLimit, 60)) {
                $skipped_budget += count($chunk);
                break;
            }

            try {
                $vectors = embeddings_embed_texts($texts);
                $embedded += count($vectors);
            } catch (Throwable $e) {
                break;
            }

            $points = [];
            foreach ($vectors as $i => $vec) {
                $spec = $pointSpecs[$i] ?? null;
                if (!is_array($spec)) continue;
                $points[] = [
                    'id' => $spec['id'],
                    'vector' => $vec,
                    'payload' => (array)($spec['payload'] ?? []),
                ];
            }

            if ($points === []) {
                break;
            }

            $ok = false;
            try {
                $ok = vector_upsert($tenantId, $points);
            } catch (Throwable $e) {
                $ok = false;
            }
            if (!$ok) {
                break;
            }

            $written += count($points);
            foreach ($chunk as $e) {
                $filePath = (string)($e['_file'] ?? '');
                if ($filePath !== '' && is_file($filePath)) {
                    mh_memory_move_to_dir($filePath, (string)($cfg['processed_dir'] ?? 'processed'));
                    $processed++;
                }
                $logRow = [
                    'id' => is_string($e['id'] ?? null) ? (string)$e['id'] : null,
                    'tenant_id' => $tenantId,
                    'persona_id' => is_string($e['persona_id'] ?? null) ? (string)$e['persona_id'] : '',
                    'meta_human_id' => is_string($e['meta_human_id'] ?? null) ? (string)$e['meta_human_id'] : '',
                    'kind' => is_string($e['kind'] ?? null) ? (string)$e['kind'] : 'text',
                    'text' => (string)$e['_text'],
                    'created_at_utc' => is_string($e['created_at_utc'] ?? null) ? (string)$e['created_at_utc'] : gmdate('c'),
                ];
                mh_memory_write_log($tenantId, $logRow);
            }
        }
    }

    return [
        'processed' => $processed,
        'embedded' => $embedded,
        'written' => $written,
        'skipped_budget' => $skipped_budget,
    ];
}

function mh_memory_consolidate_once(): array
{
    $cfg = mh_memory_config();
    $cons = is_array($cfg['consolidation'] ?? null) ? (array)$cfg['consolidation'] : [];
    if (empty($cons['enabled'])) {
        return ['ok' => true, 'skipped' => 'disabled'];
    }

    $runsLimit = max(1, (int)($cons['max_runs_per_hour'] ?? 4));
    if (!mh_memory_budget_take('consolidation_runs', 1, $runsLimit, 3600)) {
        return ['ok' => true, 'skipped' => 'budget'];
    }

    $windowHours = max(1, (int)($cons['window_hours'] ?? 24));
    $maxItems = max(1, (int)($cons['max_items'] ?? 200));
    $maxChars = max(200, (int)($cons['summary_max_chars'] ?? 2400));
    $cutoff = time() - ($windowHours * 3600);
    $deepCfg = mh_memory_llm_config();
    $deepEnabled = !empty($deepCfg['enabled']);
    $idleSeconds = max(60, (int)($deepCfg['idle_seconds'] ?? 3600));
    $minBetween = max(3600, (int)($deepCfg['min_seconds_between_runs'] ?? 86400));
    $deepMaxPrompt = max(2000, (int)($deepCfg['max_prompt_chars'] ?? 12000));
    $deepMaxResp = max(800, (int)($deepCfg['max_response_chars'] ?? 4000));
    $deepReqGlobal = (int)($deepCfg['max_requests_per_day_global'] ?? 0);
    if ($deepReqGlobal <= 0) $deepReqGlobal = (int)($deepCfg['max_requests_per_day'] ?? 24);
    $deepReqGlobal = max(1, $deepReqGlobal);
    $deepReqPerTenant = max(1, (int)($deepCfg['max_requests_per_day_per_tenant'] ?? 10));
    $deepReqPerScope = max(1, (int)($deepCfg['max_requests_per_day_per_scope'] ?? 1));
    $now = time();

    $tenantDirs = glob('/data/tenants/*') ?: [];
    $summaries = 0;
    $deepSummaries = 0;
    foreach ($tenantDirs as $tenantDir) {
        if (!is_dir($tenantDir)) continue;
        $logPath = rtrim((string)$tenantDir, '/') . '/memory/log.jsonl';
        if (!is_file($logPath)) continue;

        $rows = mh_memory_load_candidate_rows($logPath, $maxItems, $cutoff);
        if ($rows === []) continue;

        $byScope = [];
        foreach ($rows as $r) {
            $tenantId = is_string($r['tenant_id'] ?? null) ? (string)$r['tenant_id'] : '';
            $personaId = is_string($r['persona_id'] ?? null) ? (string)$r['persona_id'] : '';
            $metaHumanId = is_string($r['meta_human_id'] ?? null) ? (string)$r['meta_human_id'] : '';
            if ($tenantId === '') continue;
            $scopeKey = $tenantId . '|' . $personaId . '|' . $metaHumanId;
            $byScope[$scopeKey][] = $r;
        }

        foreach ($byScope as $scopeKey => $scopeRows) {
            [$tenantId, $personaId, $metaHumanId] = array_pad(explode('|', (string)$scopeKey, 3), 3, '');
            if ($tenantId === '') continue;

            $lastActivity = 0;
            foreach ($scopeRows as $r) {
                $ts = isset($r['created_at_utc']) ? strtotime((string)$r['created_at_utc']) : 0;
                if ($ts > $lastActivity) $lastActivity = (int)$ts;
            }

            $seen = [];
            $lines = [];
            foreach ($scopeRows as $r) {
                $t = is_string($r['text'] ?? null) ? trim((string)$r['text']) : '';
                if ($t === '') continue;
                $k = strtolower(preg_replace('/\\s+/', ' ', $t));
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $lines[] = $t;
            }
            if ($lines === []) continue;

            $summary = '';
            foreach ($lines as $l) {
                $next = $summary === '' ? $l : ($summary . "\n" . $l);
                if (strlen($next) > $maxChars) break;
                $summary = $next;
            }
            $summary = trim($summary);
            if ($summary === '') continue;

            $embCfg = is_array($cfg['embeddings'] ?? null) ? (array)$cfg['embeddings'] : [];
            $reqLimit = max(1, (int)($embCfg['max_requests_per_minute'] ?? 60));
            $charLimit = max(1, (int)($embCfg['max_text_chars_per_minute'] ?? 200000));
            $charCount = strlen($summary);
            if (!mh_memory_budget_take('embeddings_requests', 1, $reqLimit, 60) || !mh_memory_budget_take('embeddings_chars', $charCount, $charLimit, 60)) {
                break 2;
            }

            try {
                $vec = embeddings_embed_text($summary);
            } catch (Throwable $e) {
                continue;
            }

            $payload = [
                'tenant_id' => $tenantId,
                'persona_id' => $personaId,
                'meta_human_id' => $metaHumanId,
                'kind' => 'consolidation',
                'text' => $summary,
                'created_at_utc' => gmdate('c'),
                'source' => 'memory_daemon',
            ];
            $id = 'consolidation_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
            try {
                $ok = vector_upsert($tenantId, [[
                    'id' => $id,
                    'vector' => $vec,
                    'payload' => $payload,
                ]]);
                if ($ok) $summaries++;
            } catch (Throwable $e) {
            }

            if ($deepEnabled && $lastActivity > 0 && ($now - $lastActivity) >= $idleSeconds && count($scopeRows) >= 10) {
                $tenantState = mh_memory_deep_load_state($tenantId);
                $scopes = isset($tenantState['scopes']) && is_array($tenantState['scopes']) ? $tenantState['scopes'] : [];
                $scopeId = $personaId . '|' . $metaHumanId;
                $prevTs = 0;
                if (isset($scopes[$scopeId]) && is_array($scopes[$scopeId]) && isset($scopes[$scopeId]['last_deep_at'])) {
                    $prevTs = (int)$scopes[$scopeId]['last_deep_at'];
                }
                if ($prevTs <= 0 || ($now - $prevTs) >= $minBetween) {
                    $budgetOk = true;
                    $budgetOk = $budgetOk && mh_memory_budget_take('deep_llm_requests_global', 1, $deepReqGlobal, 86400);
                    $budgetOk = $budgetOk && mh_memory_budget_take_scoped('deep_llm_requests_tenant', 1, $deepReqPerTenant, 86400, $tenantId, null);
                    $budgetOk = $budgetOk && mh_memory_budget_take_scoped('deep_llm_requests_scope', 1, $deepReqPerScope, 86400, $tenantId, $scopeId);
                    if ($budgetOk) {
                        $promptLines = [];
                        foreach ($scopeRows as $r) {
                            $t = is_string($r['text'] ?? null) ? trim((string)$r['text']) : '';
                            if ($t === '') continue;
                            $created = is_string($r['created_at_utc'] ?? null) ? (string)$r['created_at_utc'] : '';
                            $promptLines[] = ($created !== '' ? '[' . $created . '] ' : '') . $t;
                        }
                        $promptBody = implode("\n", array_slice($promptLines, -200));
                        if (strlen($promptBody) > $deepMaxPrompt) {
                            $promptBody = substr($promptBody, -$deepMaxPrompt);
                        }
                        $system = "You are the MetaHumans always-on memory sleep cycle consolidator. Produce a concise consolidation that is safe to store as long-term memory.";
                        $user = "Tenant: {$tenantId}\nPersona: {$personaId}\nMetaHuman: {$metaHumanId}\n\nUser is idle (> {$idleSeconds}s). Perform deep consolidation within 24h cadence.\n\nReturn plain text only. Max " . $deepMaxResp . " characters. Focus on durable facts, preferences, ongoing tasks, people/projects, and recent changes.\n\nEvents:\n" . $promptBody;
                        $resp = mh_memory_llm_chat([
                            ['role' => 'system', 'content' => $system],
                            ['role' => 'user', 'content' => $user],
                        ]);
                        if (($resp['ok'] ?? null) === true && isset($resp['content']) && is_string($resp['content'])) {
                            $deepText = trim((string)$resp['content']);
                            if (strlen($deepText) > $deepMaxResp) {
                                $deepText = substr($deepText, 0, $deepMaxResp);
                            }
                            $deepStored = false;
                            $embCfg = is_array($cfg['embeddings'] ?? null) ? (array)$cfg['embeddings'] : [];
                            $reqLimit = max(1, (int)($embCfg['max_requests_per_minute'] ?? 60));
                            $charLimit = max(1, (int)($embCfg['max_text_chars_per_minute'] ?? 200000));
                            $charCount = strlen($deepText);
                            if (mh_memory_budget_take('embeddings_requests', 1, $reqLimit, 60) && mh_memory_budget_take('embeddings_chars', $charCount, $charLimit, 60)) {
                                try {
                                    $vec2 = embeddings_embed_text($deepText);
                                    $payload2 = [
                                        'tenant_id' => $tenantId,
                                        'persona_id' => $personaId,
                                        'meta_human_id' => $metaHumanId,
                                        'kind' => 'deep_consolidation',
                                        'text' => $deepText,
                                        'created_at_utc' => gmdate('c'),
                                        'source' => 'memory_daemon_llm',
                                        'idle_seconds' => $idleSeconds,
                                    ];
                                    $id2 = 'deep_consolidation_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
                                    $ok2 = vector_upsert($tenantId, [[
                                        'id' => $id2,
                                        'vector' => $vec2,
                                        'payload' => $payload2,
                                    ]]);
                                    mh_memory_deep_append_log($tenantId, [
                                        'ts' => $now,
                                        'tenant_id' => $tenantId,
                                        'persona_id' => $personaId,
                                        'meta_human_id' => $metaHumanId,
                                        'scope_id' => $scopeId,
                                        'ok' => (bool)$ok2,
                                        'id' => $id2,
                                    ]);
                                    if ($ok2) {
                                        $deepStored = true;
                                        $deepSummaries++;
                                        $scopes[$scopeId] = [
                                            'last_deep_at' => $now,
                                            'last_activity_at' => $lastActivity,
                                            'updated_at_utc' => gmdate('c'),
                                        ];
                                        $tenantState['scopes'] = $scopes;
                                        mh_memory_deep_save_state($tenantId, $tenantState);
                                    }
                                } catch (Throwable $e) {
                                }
                            }
                            if (!$deepStored) {
                                mh_memory_release_deep_request_budgets($tenantId, $scopeId);
                            }
                        } else {
                            mh_memory_release_deep_request_budgets($tenantId, $scopeId);
                            mh_memory_deep_append_log($tenantId, [
                                'ts' => $now,
                                'tenant_id' => $tenantId,
                                'persona_id' => $personaId,
                                'meta_human_id' => $metaHumanId,
                                'scope_id' => $scopeId,
                                'ok' => false,
                                'error' => is_string($resp['error'] ?? null) ? (string)$resp['error'] : 'llm_failed',
                            ]);
                        }
                    } else {
                        mh_memory_deep_append_log($tenantId, [
                            'ts' => $now,
                            'tenant_id' => $tenantId,
                            'persona_id' => $personaId,
                            'meta_human_id' => $metaHumanId,
                            'scope_id' => $scopeId,
                            'ok' => false,
                            'error' => 'budget_exceeded',
                        ]);
                    }
                }
            }
        }
    }

    return ['ok' => true, 'summaries_written' => $summaries, 'deep_summaries_written' => $deepSummaries];
}

if (php_sapi_name() === 'cli') {
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $script !== '' ? (realpath($script) ?: $script) : '';
    if ($real !== '' && (realpath(__FILE__) ?: __FILE__) === $real) {
        $cmd = $argv[1] ?? '';
        $cmd = is_string($cmd) ? strtolower(trim($cmd)) : '';
        if ($cmd === '') $cmd = 'ingest';

        if ($cmd === 'ingest') {
            $res = mh_memory_ingest_once();
            echo json_encode(['ok' => true, 'command' => 'ingest', 'result' => $res], JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }

        if ($cmd === 'consolidate') {
            $res = mh_memory_consolidate_once();
            echo json_encode(['ok' => true, 'command' => 'consolidate', 'result' => $res], JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }

        if ($cmd === 'run') {
            $sleep = (int)mh_memory_cli_arg('sleep', 10);
            $sleep = max(1, $sleep);
            $lastCons = 0;
            while (true) {
                mh_memory_ingest_once();
                $cons = is_array(mh_memory_config()['consolidation'] ?? null) ? (array)mh_memory_config()['consolidation'] : [];
                $interval = max(60, (int)($cons['interval_seconds'] ?? 1800));
                if (time() - $lastCons >= $interval) {
                    mh_memory_consolidate_once();
                    $lastCons = time();
                }
                sleep($sleep);
            }
        }

        echo json_encode(['ok' => false, 'error' => 'unknown_command', 'command' => $cmd], JSON_UNESCAPED_SLASHES) . "\n";
        exit(2);
    }
}
