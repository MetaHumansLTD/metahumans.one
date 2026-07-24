<?php
declare(strict_types=1);

header('Content-Type: application/json');

function envv(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        return $default;
    }
    return $v;
}

function json_in(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function out(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function post_json(string $url, array $payload, array $headers = [], int $timeout = 120): array {
    $ch = curl_init($url);
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ch = null;
    $parsed = null;
    if (is_string($body) && $body !== '') {
        $tmp = json_decode($body, true);
        if (is_array($tmp)) {
            $parsed = $tmp;
        }
    }
    return [
        'ok' => $err === '' && $code >= 200 && $code < 300,
        'status' => $code,
        'error' => $err,
        'json' => $parsed,
        'raw' => is_string($body) ? $body : '',
    ];
}

function mh_orchestrate(array $request): array {
    $tenantId = (string)($request['tenant_id'] ?? '');
    $personaId = (string)($request['persona_id'] ?? '');
    $userId = (string)($request['user_id'] ?? '');
    $textInput = (string)($request['input']['text'] ?? '');
    $images = $request['input']['images'] ?? [];
    $cameraFrames = $request['input']['camera_frames'] ?? [];
    $routeHint = (string)($request['route_hint'] ?? 'auto');
    $taskType = (string)($request['task_type'] ?? 'general');
    $tools = $request['tools'] ?? [];
    $memory = $request['memory'] ?? [];

    if ($tenantId === '' || $personaId === '' || $userId === '') {
        return [400, ['error' => 'missing_identity_fields', 'required' => ['tenant_id', 'persona_id', 'user_id']]];
    }

    if ($textInput === '' && empty($images) && empty($cameraFrames)) {
        return [400, ['error' => 'missing_input', 'required' => ['input.text or input.images or input.camera_frames']]];
    }

    $queenModel = envv('QUEEN_MODEL', 'Hermes-4-405B');
    $queenUrl = envv('QUEEN_URL', 'https://metahumans.one/ai/chat.php');
    $queenApiKey = envv('QUEEN_API_KEY');

    $visionUrl = envv('VISION_URL');
    $visionModel = envv('VISION_MODEL', 'llama-3.2-vision-11b');
    $visionApiKey = envv('VISION_API_KEY');

    $specialists = [
        'kimi' => [
            'url' => envv('KIMI_URL'),
            'model' => envv('KIMI_MODEL', 'kimi-k2.5'),
            'api_key' => envv('KIMI_API_KEY'),
        ],
        'deepseek' => [
            'url' => envv('DEEPSEEK_URL'),
            'model' => envv('DEEPSEEK_MODEL', 'deepseek-r1'),
            'api_key' => envv('DEEPSEEK_API_KEY'),
        ],
        'nemotron' => [
            'url' => envv('NEMOTRON_URL'),
            'model' => envv('NEMOTRON_MODEL', 'openreasoning-nemotron-32b'),
            'api_key' => envv('NEMOTRON_API_KEY'),
        ],
    ];

    $contracts = [
        'queen' => [
            'role' => 'primary_orchestrator',
            'model' => $queenModel,
            'url' => $queenUrl,
        ],
        'specialists' => [
            ['name' => 'kimi', 'contract' => 'long_context_reasoning'],
            ['name' => 'deepseek', 'contract' => 'math_logic_code_reasoning'],
            ['name' => 'nemotron', 'contract' => 'high_throughput_local_reasoning'],
        ],
        'tool_call_schema' => [
            'type' => 'object',
            'required' => ['name', 'arguments'],
            'properties' => [
                'name' => ['type' => 'string'],
                'arguments' => ['type' => 'object'],
                'tenant_id' => ['type' => 'string'],
                'persona_id' => ['type' => 'string'],
                'user_id' => ['type' => 'string'],
                'idempotency_key' => ['type' => 'string'],
            ],
        ],
    ];

    $selectedSpecialist = null;
    if ($routeHint !== 'auto') {
        $selectedSpecialist = $routeHint;
    } else {
        if (in_array($taskType, ['deep_reasoning', 'research', 'planning'], true)) {
            $selectedSpecialist = 'kimi';
        } elseif (in_array($taskType, ['math', 'coding', 'debug', 'proof'], true)) {
            $selectedSpecialist = 'deepseek';
        } elseif (in_array($taskType, ['chat_fast', 'ops_local'], true)) {
            $selectedSpecialist = 'nemotron';
        }
    }

    $visionOutput = null;
    if ((!empty($images) || !empty($cameraFrames)) && $visionUrl !== null) {
        $visionText = "Analyze visual input for persona interaction. Return concise JSON with objects, scene, OCR, intent cues.";
        $visionPayload = [
            'model' => $visionModel,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a perception model for a Meta Human. Return compact factual output.'],
                ['role' => 'user', 'content' => $visionText . "\nimages=" . json_encode($images) . "\ncamera_frames=" . json_encode($cameraFrames)],
            ],
            'temperature' => 0.1,
            'max_tokens' => 512,
        ];
        $vh = [];
        if ($visionApiKey) {
            $vh['Authorization'] = 'Bearer ' . $visionApiKey;
        }
        $visionResp = post_json($visionUrl, $visionPayload, $vh, 120);
        $visionOutput = [
            'ok' => $visionResp['ok'],
            'status' => $visionResp['status'],
            'content' => $visionResp['json']['choices'][0]['message']['content'] ?? null,
        ];
    }

    $specialistOutput = null;
    if ($selectedSpecialist && isset($specialists[$selectedSpecialist])) {
        $sp = $specialists[$selectedSpecialist];
        if (!empty($sp['url'])) {
            $specialistPayload = [
                'model' => $sp['model'],
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a specialist model. Return concise, correct output for orchestration handoff.'],
                    ['role' => 'user', 'content' => $textInput],
                ],
                'temperature' => 0.2,
                'max_tokens' => 1024,
            ];
            $sh = [];
            if (!empty($sp['api_key'])) {
                $sh['Authorization'] = 'Bearer ' . $sp['api_key'];
            }
            $sresp = post_json($sp['url'], $specialistPayload, $sh, 180);
            $specialistOutput = [
                'name' => $selectedSpecialist,
                'ok' => $sresp['ok'],
                'status' => $sresp['status'],
                'content' => $sresp['json']['choices'][0]['message']['content'] ?? null,
            ];
        }
    }

    $queenSystem = [
        'You are the queen orchestrator model for Meta Humans.',
        'Apply persona constraints and produce final response contract.',
        'Use tool schema exactly when requesting tools.',
        'Keep output concise and safe.',
        'Identity scope: tenant_id=' . $tenantId . ', persona_id=' . $personaId . ', user_id=' . $userId,
        'Tool schema: ' . json_encode($contracts['tool_call_schema'], JSON_UNESCAPED_SLASHES),
    ];

    $queenUser = [
        'text_input' => $textInput,
        'task_type' => $taskType,
        'vision_output' => $visionOutput,
        'specialist_output' => $specialistOutput,
        'memory' => $memory,
        'tools' => $tools,
    ];

    $queenPayload = [
        'model' => $queenModel,
        'messages' => [
            ['role' => 'system', 'content' => implode("\n", $queenSystem)],
            ['role' => 'user', 'content' => json_encode($queenUser, JSON_UNESCAPED_SLASHES)],
        ],
        'temperature' => 0.4,
        'max_tokens' => 1200,
    ];

    $qh = [];
    if ($queenApiKey) {
        $qh['Authorization'] = 'Bearer ' . $queenApiKey;
    }

    $queenResp = post_json((string)$queenUrl, $queenPayload, $qh, 240);
    $queenJson = $queenResp['json'];
    if (is_array($queenJson) && array_key_exists('raw', $queenJson) && is_array($queenJson['raw'] ?? null)) {
        $queenJson = $queenJson['raw'];
    } elseif (is_array($queenJson) && array_key_exists('reply', $queenJson) && is_string($queenJson['reply'] ?? null) && !isset($queenJson['choices'])) {
        $queenJson = [
            'id' => 'chatcmpl_proxy_' . bin2hex(random_bytes(6)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => (string)$queenModel,
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => (string)$queenJson['reply']],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    if (!$queenResp['ok']) {
        $fallbackOrder = ['nemotron', 'deepseek', 'kimi'];
        foreach ($fallbackOrder as $fb) {
            $sp = $specialists[$fb] ?? null;
            if (!$sp || empty($sp['url'])) {
                continue;
            }
            $payload = [
                'model' => $sp['model'],
                'messages' => [
                    ['role' => 'system', 'content' => 'You are fallback orchestrator. Provide concise final answer.'],
                    ['role' => 'user', 'content' => $textInput],
                ],
                'temperature' => 0.3,
                'max_tokens' => 900,
            ];
            $fh = [];
            if (!empty($sp['api_key'])) {
                $fh['Authorization'] = 'Bearer ' . $sp['api_key'];
            }
            $fresp = post_json($sp['url'], $payload, $fh, 180);
            if ($fresp['ok']) {
                return [200, [
                    'ok' => true,
                    'source' => 'fallback',
                    'fallback_model' => $fb,
                    'trace' => [
                        'tenant_id' => $tenantId,
                        'persona_id' => $personaId,
                        'user_id' => $userId,
                        'selected_specialist' => $selectedSpecialist,
                        'contracts' => $contracts,
                        'routing' => [
                            'route_hint' => $routeHint,
                            'task_type' => $taskType,
                            'queen_failed_status' => $queenResp['status'],
                        ],
                    ],
                    'result' => $fresp['json'],
                ]];
            }
        }
        return [502, [
            'ok' => false,
            'error' => 'queen_and_fallback_failed',
            'trace' => [
                'queen_status' => $queenResp['status'],
                'queen_error' => $queenResp['error'],
            ],
        ]];
    }

    return [200, [
        'ok' => true,
        'source' => 'queen',
        'trace' => [
            'tenant_id' => $tenantId,
            'persona_id' => $personaId,
            'user_id' => $userId,
            'selected_specialist' => $selectedSpecialist,
            'contracts' => $contracts,
            'routing' => [
                'route_hint' => $routeHint,
                'task_type' => $taskType,
            ],
        ],
        'result' => $queenJson,
    ]];
}

if (!defined('MH_ORCH_LIB')) {
    $request = json_in();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        out(405, ['error' => 'method_not_allowed']);
    }
    [$status, $payload] = mh_orchestrate($request);
    out((int)$status, $payload);
}
