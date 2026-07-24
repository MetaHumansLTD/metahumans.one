<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

if (function_exists('cue_autoload')) {
    cue_autoload('models');
}

$models = function_exists('models_get_models') ? models_get_models() : [];
if (!is_array($models)) $models = [];

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) $input = [];

$task = isset($input['task']) ? strtolower(trim((string)$input['task'])) : '';
$preferred = isset($input['preferred']) ? trim((string)$input['preferred']) : '';
$mode = isset($input['mode']) ? strtolower(trim((string)$input['mode'])) : '';
$hasUploads = !empty($input['has_uploads']);

$tritonHost = getenv('META_HUMANS_TRITON_HOST');
$tritonHost = is_string($tritonHost) ? trim($tritonHost) : '';
if ($tritonHost === '') $tritonHost = 'meta.superhumans.one';
$tritonMainHttp = getenv('META_HUMANS_TRITON_MAIN_HTTP');
$tritonMainHttp = is_string($tritonMainHttp) ? trim($tritonMainHttp) : '';
if ($tritonMainHttp === '') $tritonMainHttp = "http://{$tritonHost}:30080";
$tritonGlmHttp = getenv('META_HUMANS_TRITON_GLM_HTTP');
$tritonGlmHttp = is_string($tritonGlmHttp) ? trim($tritonGlmHttp) : '';
if ($tritonGlmHttp === '') $tritonGlmHttp = "http://{$tritonHost}:30280";

$tritonProbe = function(string $baseUrl): array {
    $baseUrl = rtrim($baseUrl, '/');
    $ready = false;
    $models = [];
    try {
        $r1 = @file_get_contents($baseUrl . '/v2/health/ready');
        $ready = $r1 !== false;
    } catch (Throwable $e) {}
    if ($ready) {
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => '{}', 'timeout' => 3]];
        $ctx = stream_context_create($opts);
        try {
            $raw = @file_get_contents($baseUrl . '/v2/repository/index?ready=1', false, $ctx);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json)) {
                foreach ($json as $it) {
                    if (!is_array($it)) continue;
                    $name = isset($it['name']) ? (string)$it['name'] : '';
                    if ($name === '') continue;
                    $models[] = [
                        'name' => $name,
                        'version' => isset($it['version']) ? (string)$it['version'] : '',
                        'state' => isset($it['state']) ? (string)$it['state'] : '',
                    ];
                }
            }
        } catch (Throwable $e) {}
    }
    return ['base_url' => $baseUrl, 'ready' => $ready, 'models' => $models];
};

$infra = [
    'triton' => [
        'nemotron' => $tritonProbe($tritonMainHttp),
        'glm_canary' => $tritonProbe($tritonGlmHttp),
    ],
];

$available = [];
foreach ($models as $m) {
    if (is_array($m)) {
        $id = isset($m['id']) ? (string)$m['id'] : (isset($m['model']) ? (string)$m['model'] : '');
        if ($id === '') continue;
        $available[$id] = $m;
    } elseif (is_string($m)) {
        $available[$m] = ['id' => $m];
    }
}

$pickFirstMatch = function(array $needles) use ($available, $infra): string {
    foreach ($needles as $needle) {
        foreach (array_keys($available) as $id) {
            if ($needle !== '' && stripos($id, $needle) !== false) {
                return $id;
            }
        }
    }
    $nemotronNames = [];
    foreach (['nemotron', 'glm_canary'] as $k) {
        $arr = $infra['triton'][$k]['models'] ?? [];
        if (!is_array($arr)) continue;
        foreach ($arr as $m) {
            if (is_array($m) && isset($m['name'])) {
                $nemotronNames[] = (string)$m['name'];
            }
        }
    }
    foreach ($needles as $needle) {
        foreach ($nemotronNames as $name) {
            if ($needle !== '' && stripos($name, $needle) !== false) return $name;
        }
    }
    return '';
};

$inferIntent = function(string $task, string $mode, bool $hasUploads): string {
    if ($hasUploads) return 'vision';
    if ($mode === 'build') return 'code';
    if (preg_match('/\\b(code|function|class|typescript|javascript|ts\\b|js\\b|php\\b|python\\b|sql\\b|refactor|bug|fix|compile|build)\\b/i', $task)) {
        return 'code';
    }
    if (preg_match('/\\b(reason|reasoning|proof|logic|derive|math|verifier|chain|think)\\b/i', $task)) {
        return 'reasoning';
    }
    return 'chat';
};

$pick = function(string $task, string $mode, bool $hasUploads, string $preferred) use ($available, $pickFirstMatch, $inferIntent): array {
    $intent = $inferIntent($task, $mode, $hasUploads);

    $nemotron = $pickFirstMatch(['nemotron', 'openreasoning', 'tensorrt_llm']);
    $glm = $pickFirstMatch(['glm-4.7', 'glm', 'GLM']);

    $fallbackOrder = [];
    if ($intent === 'reasoning') {
        $fallbackOrder = [$nemotron, $glm];
    } elseif ($intent === 'code') {
        $fallbackOrder = [$glm, $nemotron];
    } elseif ($intent === 'vision') {
        $fallbackOrder = [$glm, $nemotron];
    } else {
        $fallbackOrder = [$nemotron, $glm];
    }
    $fallbackOrder = array_values(array_filter($fallbackOrder, fn($x) => is_string($x) && $x !== ''));

    if ($preferred !== '' && isset($available[$preferred])) {
        $selected = $preferred;
    } else {
        $selected = $fallbackOrder[0] ?? (array_key_first($available) ?? '');
    }

    $fallback = $fallbackOrder[0] ?? (array_key_first($available) ?? '');
    if ($fallback === '') $fallback = $selected;

    return [
        'selected' => $selected,
        'fallback' => $fallback,
        'intent' => $intent,
        'candidates' => $fallbackOrder,
        'nemotron' => $nemotron,
        'glm' => $glm,
    ];
};

if ($task !== '' || $preferred !== '' || $mode !== '' || $hasUploads) {
    $r = $pick($task, $mode, (bool)$hasUploads, $preferred);
    mhw_json([
        'success' => true,
        'task' => $task,
        'mode' => $mode,
        'has_uploads' => (bool)$hasUploads,
        'preferred' => $preferred,
        'selected' => $r['selected'],
        'fallback' => $r['fallback'],
        'intent' => $r['intent'],
        'candidates' => $r['candidates'],
        'anchors' => [
            'nemotron' => $r['nemotron'],
            'glm' => $r['glm'],
        ],
        'infrastructure' => $infra,
        'count' => count($available),
    ]);
    exit;
}

mhw_json([
    'success' => true,
    'context' => [
        'tenant_id' => $ctx['tenant_id'],
        'persona_id' => $ctx['persona_id'],
        'meta_human_id' => $ctx['meta_human_id'],
    ],
    'infrastructure' => $infra,
    'models' => array_values($available),
    'count' => count($available),
]);
